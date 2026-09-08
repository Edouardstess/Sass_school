<?php

declare(strict_types=1);

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\Grade;
use App\Domain\Academic\Models\GradeRevision;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Recording and amending marks.
 *
 * Every write is guarded twice: the assessment (and its period) must still be
 * open, and the score must fit the assessment's own scale. Every *amendment*
 * additionally writes a `grade_revisions` row — the "modification contrôlée"
 * requirement, so a changed mark always carries who changed it, from what, to
 * what and why.
 */
final class GradeService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Record or amend one student's mark.
     *
     * @param  float|null  $score  null with `$isAbsent` marks a missed
     *                             assessment, which is excluded from averages
     *                             rather than counted as zero
     */
    public function record(
        Assessment $assessment,
        string $studentId,
        ?float $score,
        bool $isAbsent = false,
        bool $isExcused = false,
        ?string $comment = null,
        ?User $actor = null,
        ?string $reason = null,
    ): Grade {
        $this->assertWritable($assessment);
        $this->assertScoreFits($assessment, $score, $isAbsent);

        return DB::transaction(function () use (
            $assessment, $studentId, $score, $isAbsent, $isExcused, $comment, $actor, $reason
        ): Grade {
            $grade = Grade::query()
                ->where('assessment_id', $assessment->id)
                ->where('student_id', $studentId)
                ->lockForUpdate()
                ->first();

            if ($grade === null) {
                $grade = new Grade;
                $grade->forceFill([
                    'school_id' => $this->tenant->idOrFail(),
                    'assessment_id' => $assessment->id,
                    'student_id' => $studentId,
                    'recorded_by' => $actor?->id,
                    'score' => $score,
                    'is_absent' => $isAbsent,
                    'is_excused' => $isExcused,
                    'comment' => $comment,
                ])->save();

                return $grade;
            }

            $previous = $grade->score === null ? null : (float) $grade->score;

            $grade->forceFill([
                'score' => $score,
                'is_absent' => $isAbsent,
                'is_excused' => $isExcused,
                'comment' => $comment,
                'recorded_by' => $actor->id ?? $grade->recorded_by,
            ])->save();

            // Only an actual change of value is an amendment worth recording;
            // re-saving the same mark is not.
            if ($previous !== $score) {
                GradeRevision::query()->create([
                    'school_id' => $grade->school_id,
                    'grade_id' => $grade->id,
                    'changed_by' => $actor?->id,
                    'old_score' => $previous,
                    'new_score' => $score,
                    'reason' => $reason,
                    'created_at' => now(),
                ]);

                $this->audit->log(AuditAction::GradeUpdate, $grade, [
                    'description' => sprintf(
                        'Grade amended on "%s" from %s to %s',
                        $assessment->title,
                        $previous === null ? '—' : (string) $previous,
                        $score === null ? '—' : (string) $score,
                    ),
                    'old_values' => ['score' => $previous],
                    'new_values' => ['score' => $score, 'reason' => $reason],
                ]);
            }

            return $grade;
        });
    }

    /**
     * Record a whole class in one transaction.
     *
     * All-or-nothing on purpose: a teacher submitting thirty marks should not
     * end up with nineteen saved and the rest lost because row twenty was
     * malformed.
     *
     * @param  list<array{student_id: string, score?: float|null, is_absent?: bool, is_excused?: bool, comment?: string|null}>  $entries
     * @return array{recorded: int}
     */
    public function recordBatch(Assessment $assessment, array $entries, ?User $actor = null): array
    {
        $this->assertWritable($assessment);

        return DB::transaction(function () use ($assessment, $entries, $actor): array {
            $count = 0;

            foreach ($entries as $entry) {
                $this->record(
                    assessment: $assessment,
                    studentId: $entry['student_id'],
                    score: $entry['score'] ?? null,
                    isAbsent: (bool) ($entry['is_absent'] ?? false),
                    isExcused: (bool) ($entry['is_excused'] ?? false),
                    comment: $entry['comment'] ?? null,
                    actor: $actor,
                );
                $count++;
            }

            return ['recorded' => $count];
        });
    }

    /**
     * Freeze an assessment's marks.
     *
     * After this only a user holding `grades.lock` can change anything, which
     * is what makes a published report card trustworthy.
     */
    public function lock(Assessment $assessment, User $actor): Assessment
    {
        if ($assessment->isLocked()) {
            return $assessment;
        }

        $assessment->forceFill([
            'status' => Assessment::STATUS_LOCKED,
            'locked_at' => now(),
            'locked_by' => $actor->id,
        ])->save();

        $this->audit->log(AuditAction::Update, $assessment, [
            'description' => "Assessment \"{$assessment->title}\" locked",
        ]);

        return $assessment;
    }

    public function unlock(Assessment $assessment, User $actor, string $reason): Assessment
    {
        $assessment->forceFill([
            'status' => Assessment::STATUS_PUBLISHED,
            'locked_at' => null,
            'locked_by' => null,
        ])->save();

        // Unlocking is rarer and more consequential than locking, so it is
        // always audited with a reason.
        $this->audit->log(AuditAction::Update, $assessment, [
            'description' => "Assessment \"{$assessment->title}\" unlocked: {$reason}",
            'metadata' => ['reason' => $reason, 'actor' => $actor->id],
        ]);

        return $assessment;
    }

    private function assertWritable(Assessment $assessment): void
    {
        if ($assessment->isLocked()) {
            throw new DomainException(__('academics.assessment_locked'));
        }

        if ($assessment->gradePeriod?->acceptsGrades() === false) {
            throw new DomainException(__('academics.period_locked'));
        }
    }

    private function assertScoreFits(Assessment $assessment, ?float $score, bool $isAbsent): void
    {
        if ($score === null) {
            if (! $isAbsent) {
                throw new DomainException(__('academics.score_required'));
            }

            return;
        }

        if ($score < 0 || $score > (float) $assessment->max_score) {
            throw new DomainException(__('academics.score_exceeds_max', [
                'score' => $score,
                'max' => $assessment->max_score,
            ]));
        }
    }
}
