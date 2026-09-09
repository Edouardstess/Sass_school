<?php

declare(strict_types=1);

namespace App\Domain\School\Services;

use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Models\GradePeriod;
use App\Domain\Academic\Models\Level;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Services\EnrollmentService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The academic-year lifecycle: draft → active → closed → archived.
 *
 * Only one year may be active per tenant. That is enforced by a partial unique
 * index, so `activate()` deactivates the incumbent inside the same transaction
 * rather than hoping no one activates two at once.
 */
final class AcademicYearService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EnrollmentService $enrollments,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): AcademicYear
    {
        return DB::transaction(function () use ($data): AcademicYear {
            $year = new AcademicYear;
            $year->forceFill([
                'school_id' => $this->tenant->idOrFail(),
                'name' => $data['name'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'status' => AcademicYear::STATUS_DRAFT,
                'grading_scale_max' => $data['grading_scale_max'] ?? config('schoolflow.grading.default_scale_max'),
                'passing_grade' => $data['passing_grade'] ?? config('schoolflow.grading.default_passing_grade'),
            ])->save();

            if (! empty($data['copy_from_year_id'])) {
                $this->copyConfiguration(
                    AcademicYear::query()->findOrFail($data['copy_from_year_id']),
                    $year,
                );
            } else {
                $this->createDefaultPeriods($year);
            }

            $this->audit->created($year, "Created academic year {$year->name}");

            return $year->load('gradePeriods');
        });
    }

    /**
     * Make this the active year.
     *
     * The previous active year is closed rather than left in limbo, so the
     * "exactly one active" invariant holds without relying on the caller to
     * tidy up.
     */
    public function activate(AcademicYear $year): AcademicYear
    {
        if ($year->isActive()) {
            return $year;
        }

        return DB::transaction(function () use ($year): AcademicYear {
            $current = AcademicYear::query()
                ->active()
                ->whereKeyNot($year->id)
                ->lockForUpdate()
                ->first();

            $current?->forceFill(['status' => AcademicYear::STATUS_CLOSED, 'closed_at' => now()])->save();

            try {
                $year->forceFill(['status' => AcademicYear::STATUS_ACTIVE, 'closed_at' => null])->save();
            } catch (UniqueConstraintViolationException) {
                throw new DomainException(__('academics.year_already_active'));
            }

            $this->audit->log(AuditAction::Update, $year, [
                'description' => "Academic year {$year->name} activated",
                'metadata' => ['previous' => $current?->name],
            ]);

            return $year;
        });
    }

    public function close(AcademicYear $year, string $status, User $actor): AcademicYear
    {
        $year->forceFill([
            'status' => $status,
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ])->save();

        $this->audit->log(AuditAction::Update, $year, [
            'description' => "Academic year {$year->name} moved to {$status}",
        ]);

        return $year;
    }

    /**
     * @param  array<string, string>  $classMapping
     * @return array{promoted: int, skipped: list<array{student_id: string, reason: string}>}
     */
    public function promote(AcademicYear $from, AcademicYear $to, array $classMapping, bool $onlyPassing): array
    {
        if ($from->id === $to->id) {
            throw new DomainException(__('academics.promote_same_year'));
        }

        return $this->enrollments->promoteYear(
            $from,
            $to,
            $classMapping,
            $onlyPassing,
            (float) $from->passing_grade,
        );
    }

    /**
     * Clone the previous year's structure: grading periods, levels, classes
     * and their subject assignments. Students are NOT copied — that is the
     * separate, deliberate act of promotion.
     */
    private function copyConfiguration(AcademicYear $source, AcademicYear $target): void
    {
        foreach ($source->gradePeriods as $period) {
            $offsetDays = $source->starts_on->diffInDays($target->starts_on, false);

            GradePeriod::query()->create([
                'school_id' => $target->school_id,
                'academic_year_id' => $target->id,
                'name' => $period->name,
                'sequence' => $period->sequence,
                'starts_on' => $period->starts_on->addDays((int) $offsetDays),
                'ends_on' => $period->ends_on->addDays((int) $offsetDays),
                'weight' => $period->weight,
            ]);
        }

        $sourceClasses = SchoolClass::query()
            ->where('academic_year_id', $source->id)
            ->with('classSubjects')
            ->get();

        foreach ($sourceClasses as $sourceClass) {
            $newClass = SchoolClass::query()->create([
                'school_id' => $target->school_id,
                'academic_year_id' => $target->id,
                'level_id' => $sourceClass->level_id,
                'name' => $sourceClass->name,
                'section' => $sourceClass->section,
                'capacity' => $sourceClass->capacity,
                'homeroom_teacher_id' => $sourceClass->homeroom_teacher_id,
                'room_id' => $sourceClass->room_id,
                'is_active' => true,
            ]);

            foreach ($sourceClass->classSubjects as $assignment) {
                ClassSubject::query()->create([
                    'school_id' => $target->school_id,
                    'school_class_id' => $newClass->id,
                    'subject_id' => $assignment->subject_id,
                    'teacher_id' => $assignment->teacher_id,
                    'coefficient' => $assignment->coefficient,
                    'weekly_hours' => $assignment->weekly_hours,
                    'is_active' => true,
                ]);
            }
        }
    }

    /** Three equally weighted trimesters, the common Haitian arrangement. */
    private function createDefaultPeriods(AcademicYear $year): void
    {
        $span = (int) $year->starts_on->diffInDays($year->ends_on);
        $chunk = (int) floor($span / 3);

        foreach ([1, 2, 3] as $sequence) {
            GradePeriod::query()->create([
                'school_id' => $year->school_id,
                'academic_year_id' => $year->id,
                'name' => "Trimestre {$sequence}",
                'sequence' => $sequence,
                'starts_on' => $year->starts_on->addDays($chunk * ($sequence - 1)),
                'ends_on' => $sequence === 3 ? $year->ends_on : $year->starts_on->addDays($chunk * $sequence),
                'weight' => 1,
            ]);
        }
    }

    /** Levels are year-agnostic, so a fresh tenant still gets a starting set. */
    public function ensureDefaultLevels(): void
    {
        if (Level::query()->exists()) {
            return;
        }

        foreach (['6ème', '5ème', '4ème', '3ème'] as $index => $name) {
            Level::query()->create([
                'school_id' => $this->tenant->idOrFail(),
                'name' => $name,
                'sequence' => $index + 1,
            ]);
        }
    }
}
