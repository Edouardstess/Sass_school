<?php

declare(strict_types=1);

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\GradePeriod;
use App\Domain\Academic\Models\ReportCard;
use App\Domain\Academic\Models\ReportCardLine;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AttendanceStatus;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\GenerateReportCardPdf;
use App\Jobs\NotifyReportCardPublished;
use Illuminate\Support\Facades\DB;

/**
 * Computes report cards for a whole class at once.
 *
 * Whole-class rather than per-student because rank, class average, minimum and
 * maximum are class-level facts: computing one card in isolation would either
 * be wrong or would re-derive the whole class anyway.
 *
 * Results are materialised into `report_cards` / `report_card_lines`. The PDF
 * is a separate, queued step — a 700-student run must not hold an HTTP worker.
 */
final class ReportCardService
{
    public function __construct(
        private readonly GradeCalculator $calculator,
        private readonly RankingService $ranking,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Recompute every card for a class and period.
     *
     * @return array{class_size: int, computed: int, class_average: float|null}
     */
    public function computeForClass(SchoolClass $class, GradePeriod $period): array
    {
        $year = $period->academicYear;
        $scaleMax = $year === null ? 100.0 : (float) $year->grading_scale_max;
        $passingGrade = $year === null ? 50.0 : (float) $year->passing_grade;

        $enrollments = Enrollment::query()
            ->where('school_class_id', $class->id)
            ->where('academic_year_id', $period->academic_year_id)
            ->where('status', 'active')
            ->with('student')
            ->get();

        if ($enrollments->isEmpty()) {
            throw new DomainException(__('academics.class_has_no_students', ['class' => $class->name]));
        }

        // Pass 1 — every student's subject averages and overall average.
        $perStudent = [];

        foreach ($enrollments as $enrollment) {
            $subjects = $this->calculator->subjectAveragesForStudent(
                studentId: $enrollment->student_id,
                schoolClassId: $class->id,
                period: $period,
                scaleMax: $scaleMax,
            );

            $perStudent[$enrollment->student_id] = [
                'subjects' => $subjects,
                'average' => $this->calculator->overallAverage($subjects),
            ];
        }

        // Pass 2 — class-level facts, which need every student's numbers.
        $overallAverages = array_map(fn (array $row): ?float => $row['average'], $perStudent);
        $ranks = $this->ranking->rank($overallAverages);
        $stats = $this->ranking->statistics($overallAverages, $passingGrade);

        $subjectRanks = $this->rankBySubject($perStudent);
        $subjectStats = $this->statisticsBySubject($perStudent);

        // Pass 3 — persist.
        $computed = 0;

        DB::transaction(function () use (
            $enrollments, $perStudent, $ranks, $stats, $subjectRanks, $subjectStats,
            $class, $period, $scaleMax, &$computed
        ): void {
            foreach ($enrollments as $enrollment) {
                $studentId = $enrollment->student_id;
                $data = $perStudent[$studentId];

                $attendance = $this->attendanceCounts($studentId, $period);

                $card = ReportCard::query()->updateOrCreate(
                    ['student_id' => $studentId, 'grade_period_id' => $period->id],
                    [
                        'school_id' => $this->tenant->idOrFail(),
                        'school_class_id' => $class->id,
                        'average' => $data['average'],
                        'rank' => $ranks[$studentId] ?? null,
                        'class_size' => $enrollments->count(),
                        'class_average' => $stats['average'],
                        'absences_count' => $attendance['absences'],
                        'late_count' => $attendance['lates'],
                        'computed_at' => now(),
                    ],
                );

                // Rebuilt rather than merged: a subject removed from the class
                // must disappear from the card, not linger with a stale mark.
                $card->lines()->delete();

                foreach ($data['subjects'] as $subjectId => $subject) {
                    ReportCardLine::query()->create([
                        'school_id' => $card->school_id,
                        'report_card_id' => $card->id,
                        'subject_id' => $subjectId,
                        'subject_name' => $subject['subject_name'],
                        'coefficient' => $subject['coefficient'],
                        'average' => $subject['average'],
                        'class_average' => $subjectStats[$subjectId]['average'] ?? null,
                        'min_score' => $subjectStats[$subjectId]['min'] ?? null,
                        'max_score' => $subjectStats[$subjectId]['max'] ?? null,
                        'rank' => $subjectRanks[$subjectId][$studentId] ?? null,
                        'appreciation' => $this->calculator->appreciationFor($subject['average'], $scaleMax),
                        'teacher_name' => $subject['teacher_name'] ?? null,
                    ]);
                }

                $computed++;
            }
        });

        $this->audit->log(AuditAction::Update, $class, [
            'description' => "Report cards computed for {$class->name} — {$period->name}",
            'metadata' => ['computed' => $computed, 'class_average' => $stats['average']],
        ]);

        return [
            'class_size' => $enrollments->count(),
            'computed' => $computed,
            'class_average' => $stats['average'],
        ];
    }

    /**
     * Publish the cards for a class, making them visible to guardians and
     * queueing the PDF render for each.
     */
    public function publishForClass(SchoolClass $class, GradePeriod $period, User $actor): int
    {
        $cards = ReportCard::query()
            ->where('school_class_id', $class->id)
            ->where('grade_period_id', $period->id)
            ->get();

        if ($cards->isEmpty()) {
            throw new DomainException(__('academics.report_card_not_ready'));
        }

        foreach ($cards as $card) {
            $card->forceFill([
                'status' => ReportCard::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $actor->id,
            ])->save();

            GenerateReportCardPdf::dispatch($card->id, $card->school_id)->onQueue('documents');
            NotifyReportCardPublished::dispatch($card->id, $card->school_id)->onQueue('notifications');
        }

        $this->audit->log(AuditAction::Update, $class, [
            'description' => "Report cards published for {$class->name} — {$period->name}",
            'metadata' => ['count' => $cards->count()],
        ]);

        return $cards->count();
    }

    /**
     * Per-subject ranking, so a card can show "3rd of 28 in mathematics".
     *
     * @param  array<string, array{subjects: array<string, array{average: float|null, coefficient: float}>, average: float|null}>  $perStudent
     * @return array<string, array<string, int|null>>
     */
    private function rankBySubject(array $perStudent): array
    {
        $bySubject = [];

        foreach ($perStudent as $studentId => $row) {
            foreach ($row['subjects'] as $subjectId => $subject) {
                $bySubject[$subjectId][$studentId] = $subject['average'];
            }
        }

        return array_map(fn (array $averages): array => $this->ranking->rank($averages), $bySubject);
    }

    /**
     * @param  array<string, array{subjects: array<string, array{average: float|null, coefficient: float}>, average: float|null}>  $perStudent
     * @return array<string, array{average: float|null, min: float|null, max: float|null}>
     */
    private function statisticsBySubject(array $perStudent): array
    {
        $bySubject = [];

        foreach ($perStudent as $row) {
            foreach ($row['subjects'] as $subjectId => $subject) {
                if ($subject['average'] !== null) {
                    $bySubject[$subjectId][] = $subject['average'];
                }
            }
        }

        $stats = [];

        // Only non-null averages are collected above, so every bucket that
        // exists here has at least one value.
        foreach ($bySubject as $subjectId => $values) {
            $stats[$subjectId] = [
                'average' => round(array_sum($values) / count($values), 2),
                'min' => round(min($values), 2),
                'max' => round(max($values), 2),
            ];
        }

        return $stats;
    }

    /** @return array{absences: int, lates: int} */
    private function attendanceCounts(string $studentId, GradePeriod $period): array
    {
        $counts = AttendanceRecord::query()
            ->where('student_id', $studentId)
            ->whereBetween('attendance_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'absences' => (int) $counts->get(AttendanceStatus::Absent->value, 0),
            'lates' => (int) $counts->get(AttendanceStatus::Late->value, 0),
        ];
    }
}
