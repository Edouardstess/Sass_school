<?php

declare(strict_types=1);

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Enums\EnrollmentStatus;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Places students into classes for an academic year.
 *
 * A student sits in exactly one class per year — enforced by a unique index on
 * (student_id, academic_year_id), which this service leans on rather than
 * duplicating with a check-then-insert that a concurrent request could slip
 * between.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function enroll(
        Student $student,
        SchoolClass $class,
        ?CarbonImmutable $enrolledOn = null,
        string $type = Enrollment::TYPE_NEW,
        ?string $notes = null,
    ): Enrollment {
        /** @var AcademicYear $year */
        $year = $class->academicYear ?? AcademicYear::query()->findOrFail($class->academic_year_id);

        if (! $year->acceptsWrites()) {
            throw new DomainException(__('academics.year_closed', ['year' => $year->name]));
        }

        // Capacity is advisory rather than a database constraint: schools
        // routinely squeeze in one more, so this is a rule an administrator
        // can be told about, not a hard wall.
        $remaining = $class->remainingSeats();

        if ($remaining !== null && $remaining <= 0) {
            throw new DomainException(__('students.class_full', ['class' => $class->name]));
        }

        try {
            return DB::transaction(function () use ($student, $class, $year, $enrolledOn, $type, $notes): Enrollment {
                $enrollment = new Enrollment;
                $enrollment->forceFill([
                    'school_id' => $this->tenant->idOrFail(),
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'school_class_id' => $class->id,
                    'enrolled_on' => ($enrolledOn ?? CarbonImmutable::now())->toDateString(),
                    'status' => EnrollmentStatus::Active->value,
                    'enrollment_type' => $type,
                    'notes' => $notes,
                ])->save();

                $this->audit->log(AuditAction::Create, $enrollment, [
                    'description' => sprintf(
                        '%s enrolled in %s for %s',
                        $student->full_name, $class->name, $year->name,
                    ),
                ]);

                return $enrollment;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException(__('students.already_enrolled'));
        }
    }

    /** Move a student to a different class within the same year. */
    public function transfer(Enrollment $enrollment, SchoolClass $target, ?string $reason = null): Enrollment
    {
        if ($enrollment->school_class_id === $target->id) {
            return $enrollment;
        }

        if ($enrollment->academic_year_id !== $target->academic_year_id) {
            throw new DomainException(__('academics.transfer_across_years'));
        }

        $before = $enrollment->getOriginal();

        $enrollment->forceFill([
            'school_class_id' => $target->id,
            'notes' => $reason ?? $enrollment->notes,
        ])->save();

        $this->audit->updated($enrollment, $before, "Transferred to class {$target->name}");

        return $enrollment;
    }

    /**
     * Promote a whole year group into the next one.
     *
     * Returns a per-student outcome rather than throwing on the first problem:
     * an end-of-year promotion of 400 students must not abort because one of
     * them has an unresolved record.
     *
     * @param  array<string, string>  $classMapping  source class id => target class id
     * @return array{promoted: int, skipped: list<array{student_id: string, reason: string}>}
     */
    public function promoteYear(
        AcademicYear $from,
        AcademicYear $to,
        array $classMapping,
        bool $onlyPassing = false,
        float $passingGrade = 50.0,
    ): array {
        $promoted = 0;
        $skipped = [];

        Enrollment::query()
            ->where('academic_year_id', $from->id)
            ->where('status', EnrollmentStatus::Active->value)
            ->with(['student', 'schoolClass'])
            ->chunkById(200, function ($enrollments) use (
                $to, $classMapping, $onlyPassing, $passingGrade, &$promoted, &$skipped
            ): void {
                foreach ($enrollments as $enrollment) {
                    $targetClassId = $classMapping[$enrollment->school_class_id] ?? null;

                    if ($targetClassId === null) {
                        $skipped[] = ['student_id' => $enrollment->student_id, 'reason' => 'no_target_class'];

                        continue;
                    }

                    if ($onlyPassing && ! $this->hasPassed($enrollment->student_id, $enrollment->academic_year_id, $passingGrade)) {
                        $skipped[] = ['student_id' => $enrollment->student_id, 'reason' => 'below_passing_grade'];

                        continue;
                    }

                    $target = SchoolClass::query()->find($targetClassId);

                    if ($target === null) {
                        $skipped[] = ['student_id' => $enrollment->student_id, 'reason' => 'target_class_missing'];

                        continue;
                    }

                    try {
                        DB::transaction(function () use ($enrollment, $target, $to): void {
                            $enrollment->forceFill([
                                'status' => EnrollmentStatus::Completed->value,
                                'ended_on' => now()->toDateString(),
                            ])->save();

                            $next = new Enrollment;
                            $next->forceFill([
                                'school_id' => $enrollment->school_id,
                                'student_id' => $enrollment->student_id,
                                'academic_year_id' => $to->id,
                                'school_class_id' => $target->id,
                                'enrolled_on' => $to->starts_on->toDateString(),
                                'status' => EnrollmentStatus::Active->value,
                                'enrollment_type' => Enrollment::TYPE_RE_ENROLLMENT,
                            ])->save();
                        });

                        $promoted++;
                    } catch (UniqueConstraintViolationException) {
                        // Already enrolled in the target year — the promotion
                        // is being re-run, which is fine.
                        $skipped[] = ['student_id' => $enrollment->student_id, 'reason' => 'already_enrolled'];
                    }
                }
            });

        $this->audit->log(AuditAction::Update, $to, [
            'description' => "Promoted {$promoted} students from {$from->name} to {$to->name}",
            'metadata' => ['promoted' => $promoted, 'skipped' => count($skipped)],
        ]);

        return ['promoted' => $promoted, 'skipped' => $skipped];
    }

    public function withdraw(Enrollment $enrollment, string $status, ?string $reason = null): Enrollment
    {
        $enrollment->forceFill([
            'status' => $status,
            'ended_on' => now()->toDateString(),
            'notes' => $reason ?? $enrollment->notes,
        ])->save();

        $this->audit->log(AuditAction::Update, $enrollment, [
            'description' => "Enrollment ended: {$status}",
        ]);

        return $enrollment;
    }

    /** Whether the student's published year average clears the pass mark. */
    private function hasPassed(string $studentId, string $academicYearId, float $passingGrade): bool
    {
        $average = DB::table('report_cards')
            ->join('grade_periods', 'report_cards.grade_period_id', '=', 'grade_periods.id')
            ->where('report_cards.student_id', $studentId)
            ->where('grade_periods.academic_year_id', $academicYearId)
            ->whereNotNull('report_cards.average')
            ->avg('report_cards.average');

        // No report card at all is not a failure — it is missing information,
        // and blocking promotion on it would penalise the school's admin lag.
        return $average === null || (float) $average >= $passingGrade;
    }
}
