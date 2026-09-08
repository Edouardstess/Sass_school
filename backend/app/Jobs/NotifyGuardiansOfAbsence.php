<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells a student's guardians they were marked absent or late.
 *
 * Idempotent twice over: the record's `parent_notified_at` short-circuits a
 * re-run, and each notification carries a dedupe key unique to
 * (record, guardian), so even a concurrent retry cannot double-send.
 */
class NotifyGuardiansOfAbsence implements ShouldQueue
{
    use Queueable, RunsInTenant;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $attendanceRecordId,
        public readonly string $schoolId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $this->inTenant($this->schoolId, function () use ($dispatcher): void {
            $record = AttendanceRecord::query()
                ->with(['student.guardians.user', 'schoolClass'])
                ->find($this->attendanceRecordId);

            if ($record === null || $record->parent_notified_at !== null) {
                return;
            }

            $student = $record->student;

            if ($student === null) {
                return;
            }

            foreach ($student->guardians as $guardian) {
                $user = $guardian->user;

                if ($user === null) {
                    continue;   // no account, nothing to notify
                }

                $dispatcher->send(
                    recipient: $user,
                    key: 'absence_alert',
                    variables: [
                        'student_name' => $student->full_name,
                        'date' => $record->attendance_date->format('d/m/Y'),
                        'class_name' => (string) $record->schoolClass?->name,
                        'school_name' => (string) $record->school?->name,
                        'status' => $record->status->label(),
                    ],
                    subject: $record,
                    dedupeKey: "absence:{$record->id}:{$guardian->id}",
                    priority: 'high',
                );
            }

            $record->forceFill(['parent_notified_at' => now()])->save();
        });
    }
}
