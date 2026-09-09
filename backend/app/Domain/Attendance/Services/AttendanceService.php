<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\AttendanceJustification;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRevision;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AttendanceStatus;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\NotifyGuardiansOfAbsence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Roll-call and its consequences.
 *
 * Two behaviours worth stating:
 *
 *  - Taking the register is idempotent. A teacher who submits twice, or
 *    corrects one pupil and resubmits, updates the existing rows rather than
 *    creating duplicates — the partial unique indexes make that safe under
 *    concurrent submissions too.
 *  - Every correction after the fact writes an `attendance_revisions` row.
 *    Attendance drives absence alerts and appears on report cards, so a
 *    silently edited register would be a real problem.
 */
final class AttendanceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Record the register for a class on one day.
     *
     * @param  list<array{student_id: string, status: string, minutes_late?: int|null, remark?: string|null}>  $entries
     * @return array{recorded: int, alerts_queued: int}
     */
    public function recordForClass(
        SchoolClass $class,
        CarbonImmutable $date,
        array $entries,
        ?User $actor = null,
        ?string $timetableEntryId = null,
    ): array {
        if ($date->isFuture()) {
            throw new DomainException(__('attendance.future_date'));
        }

        // Only students actually enrolled in this class may be marked, so a
        // crafted payload cannot write a record against someone else's child.
        $enrolled = Enrollment::query()
            ->where('school_class_id', $class->id)
            ->where('status', 'active')
            ->pluck('student_id')
            ->flip();

        $recorded = 0;
        $toAlert = [];

        DB::transaction(function () use (
            $entries, $enrolled, $class, $date, $actor, $timetableEntryId, &$recorded, &$toAlert
        ): void {
            foreach ($entries as $entry) {
                if (! $enrolled->has($entry['student_id'])) {
                    throw new DomainException(__('attendance.student_not_in_class'));
                }

                $status = AttendanceStatus::from($entry['status']);

                $record = AttendanceRecord::query()
                    ->where('student_id', $entry['student_id'])
                    ->whereDate('attendance_date', $date->toDateString())
                    ->when(
                        $timetableEntryId === null,
                        fn ($q) => $q->whereNull('timetable_entry_id'),
                        fn ($q) => $q->where('timetable_entry_id', $timetableEntryId),
                    )
                    ->lockForUpdate()
                    ->first();

                $previousStatus = $record?->status;

                if ($record === null) {
                    $record = new AttendanceRecord;
                    $record->forceFill([
                        'school_id' => $this->tenant->idOrFail(),
                        'student_id' => $entry['student_id'],
                        'school_class_id' => $class->id,
                        'academic_year_id' => $class->academic_year_id,
                        'timetable_entry_id' => $timetableEntryId,
                        'attendance_date' => $date->toDateString(),
                    ]);
                }

                $record->forceFill([
                    'status' => $status->value,
                    'minutes_late' => $status === AttendanceStatus::Late ? ($entry['minutes_late'] ?? null) : null,
                    'remark' => $entry['remark'] ?? null,
                    'recorded_by' => $actor?->id,
                ])->save();

                if ($previousStatus !== null && $previousStatus !== $status) {
                    AttendanceRevision::query()->create([
                        'school_id' => $record->school_id,
                        'attendance_record_id' => $record->id,
                        'changed_by' => $actor?->id,
                        'old_status' => $previousStatus->value,
                        'new_status' => $status->value,
                        'created_at' => now(),
                    ]);

                    $this->audit->log(AuditAction::AttendanceUpdate, $record, [
                        'description' => sprintf(
                            'Attendance corrected on %s: %s → %s',
                            $date->toDateString(), $previousStatus->value, $status->value,
                        ),
                    ]);
                }

                // Alert only on a *new* absence, and only once: re-submitting
                // the same register must not re-notify a parent.
                if ($status->triggersGuardianAlert()
                    && $record->parent_notified_at === null
                    && $previousStatus !== $status) {
                    $toAlert[] = $record->id;
                }

                $recorded++;
            }
        });

        foreach ($toAlert as $recordId) {
            NotifyGuardiansOfAbsence::dispatch($recordId, $this->tenant->idOrFail())
                ->onQueue('notifications');
        }

        return ['recorded' => $recorded, 'alerts_queued' => count($toAlert)];
    }

    /** A guardian's explanation for an absence, pending review. */
    public function submitJustification(
        AttendanceRecord $record,
        string $reason,
        User $submitter,
        ?string $documentId = null,
    ): AttendanceJustification {
        if (! $record->status->canBeJustified()) {
            throw new DomainException(__('attendance.not_justifiable'));
        }

        $pending = $record->justifications()
            ->where('status', AttendanceJustification::STATUS_PENDING)
            ->exists();

        if ($pending) {
            throw new DomainException(__('attendance.justification_pending'));
        }

        return AttendanceJustification::query()->create([
            'school_id' => $record->school_id,
            'attendance_record_id' => $record->id,
            'submitted_by' => $submitter->id,
            'reason' => $reason,
            'document_id' => $documentId,
            'status' => AttendanceJustification::STATUS_PENDING,
        ]);
    }

    /**
     * Approve or reject a justification.
     *
     * Approval flips `is_justified` on the record, which is what removes the
     * absence from the "unjustified" figures on dashboards and report cards.
     */
    public function reviewJustification(
        AttendanceJustification $justification,
        bool $approved,
        User $reviewer,
        ?string $note = null,
    ): AttendanceJustification {
        if (! $justification->isPending()) {
            throw new DomainException(__('attendance.justification_already_reviewed'));
        }

        DB::transaction(function () use ($justification, $approved, $reviewer, $note): void {
            $justification->forceFill([
                'status' => $approved
                    ? AttendanceJustification::STATUS_APPROVED
                    : AttendanceJustification::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            if ($approved) {
                $justification->attendanceRecord?->forceFill(['is_justified' => true])->save();
            }
        });

        $this->audit->log(AuditAction::AttendanceUpdate, $justification, [
            'description' => $approved ? 'Justification approved' : 'Justification rejected',
            'metadata' => ['note' => $note],
        ]);

        return $justification;
    }

    /**
     * Attendance figures for one student over a window.
     *
     * @return array{present: int, absent: int, late: int, excused: int, total: int, attendance_rate: float|null, unjustified_absences: int}
     */
    public function summaryForStudent(string $studentId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = AttendanceRecord::query()
            ->where('student_id', $studentId)
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('status, count(*) as total, count(*) filter (where is_justified) as justified')
            ->groupBy('status')
            ->toBase()
            ->get()
            ->keyBy('status');

        $get = fn (AttendanceStatus $s): int => (int) ($rows->get($s->value)->total ?? 0);

        $present = $get(AttendanceStatus::Present);
        $absent = $get(AttendanceStatus::Absent);
        $late = $get(AttendanceStatus::Late);
        $excused = $get(AttendanceStatus::Excused);
        $total = $present + $absent + $late + $excused;

        $justifiedAbsences = (int) ($rows->get(AttendanceStatus::Absent->value)->justified ?? 0);

        return [
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'total' => $total,
            // A late arrival is still an attendance; only absences count against.
            'attendance_rate' => $total === 0 ? null : round((($present + $late + $excused) / $total) * 100, 2),
            'unjustified_absences' => $absent - $justifiedAbsences,
        ];
    }
}
