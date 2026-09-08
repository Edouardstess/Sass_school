<?php

declare(strict_types=1);

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Models\Grade;
use App\Domain\Academic\Models\GradePeriod;
use Illuminate\Support\Collection;

/**
 * Turns raw marks into averages.
 *
 * Three rules govern every computation here:
 *
 *  1. **Normalisation before weighting.** A mark is stored on its own
 *     assessment's scale. A quiz out of 20 and an exam out of 100 are both
 *     converted to a percentage of the year's grading scale before they are
 *     combined, so mixing scales cannot silently distort a subject average.
 *
 *  2. **An absence is not a zero.** A grade flagged `is_absent` is excluded
 *     from the average rather than counted as 0 — a student off sick must not
 *     be punished as though they had failed. A recorded 0 *is* counted.
 *
 *  3. **No grades means no average.** An empty set yields null, never 0. A
 *     subject nobody has been marked in must render as "—" on the report card,
 *     not as a failing grade.
 *
 * Formula, matching the specification:
 *     subject average = Σ(normalised score × assessment weight) / Σ(weights)
 *     overall average = Σ(subject average × coefficient) / Σ(coefficients)
 */
final class GradeCalculator
{
    /**
     * Weighted average for one student in one subject over one period.
     *
     * @param  Collection<int, Grade>  $grades  grades whose assessments are eager loaded
     */
    public function subjectAverage(Collection $grades, float $scaleMax = 100.0): ?float
    {
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($grades as $grade) {
            if (! $grade->counts()) {
                continue;   // absent, or never marked
            }

            $assessment = $grade->assessment;

            if ($assessment === null) {
                continue;
            }

            $maxScore = (float) $assessment->max_score;

            if ($maxScore <= 0.0) {
                continue;   // guarded by a CHECK constraint, but never divide by zero
            }

            $weight = (float) $assessment->weight;

            if ($weight <= 0.0) {
                continue;
            }

            // Rebase onto the year's scale so heterogeneous marks combine.
            $normalised = ((float) $grade->score / $maxScore) * $scaleMax;

            $weightedSum += $normalised * $weight;
            $weightTotal += $weight;
        }

        if ($weightTotal === 0.0) {
            return null;
        }

        return round($weightedSum / $weightTotal, 2);
    }

    /**
     * Overall average across subjects, weighted by each subject's coefficient.
     *
     * @param  array<string, array{average: float|null, coefficient: float}>  $subjectAverages
     */
    public function overallAverage(array $subjectAverages): ?float
    {
        $weightedSum = 0.0;
        $coefficientTotal = 0.0;

        foreach ($subjectAverages as $entry) {
            if ($entry['average'] === null) {
                continue;   // an unmarked subject does not dilute the average
            }

            $coefficient = $entry['coefficient'];

            if ($coefficient <= 0.0) {
                continue;
            }

            $weightedSum += $entry['average'] * $coefficient;
            $coefficientTotal += $coefficient;
        }

        if ($coefficientTotal === 0.0) {
            return null;
        }

        return round($weightedSum / $coefficientTotal, 2);
    }

    /**
     * Every subject average for one student in one period, keyed by subject id.
     *
     * Loads the whole period's grades in a single query; computing this
     * per-subject would be N+1 across a 700-student report card run.
     *
     * @return array<string, array{average: float|null, coefficient: float, subject_name: string, class_subject_id: string, teacher_name: string|null}>
     */
    public function subjectAveragesForStudent(
        string $studentId,
        string $schoolClassId,
        GradePeriod $period,
        float $scaleMax = 100.0,
    ): array {
        $classSubjects = ClassSubject::query()
            ->where('school_class_id', $schoolClassId)
            ->where('is_active', true)
            ->with(['subject', 'teacher'])
            ->get();

        $grades = Grade::query()
            ->where('student_id', $studentId)
            ->whereHas(
                'assessment',
                fn ($q) => $q->where('grade_period_id', $period->id)
                    ->whereIn('class_subject_id', $classSubjects->pluck('id'))
            )
            ->with('assessment')
            ->get()
            ->groupBy(fn (Grade $grade): string => (string) $grade->assessment?->class_subject_id);

        $result = [];

        foreach ($classSubjects as $classSubject) {
            $subjectGrades = $grades->get($classSubject->id, collect());

            $result[$classSubject->subject_id] = [
                'average' => $this->subjectAverage($subjectGrades, $scaleMax),
                'coefficient' => (float) $classSubject->coefficient,
                'subject_name' => (string) $classSubject->subject?->name,
                'class_subject_id' => $classSubject->id,
                'teacher_name' => $classSubject->teacher?->full_name,
            ];
        }

        return $result;
    }

    /**
     * Class-level statistics for one assessment.
     *
     * @return array{count: int, average: float|null, min: float|null, max: float|null, pass_rate: float|null}
     */
    public function assessmentStatistics(Assessment $assessment, float $passingGrade = 50.0, float $scaleMax = 100.0): array
    {
        $scores = $assessment->grades()
            ->where('is_absent', false)
            ->whereNotNull('score')
            ->pluck('score')
            ->map(fn ($score): float => ((float) $score / (float) $assessment->max_score) * $scaleMax)
            ->all();

        if ($scores === []) {
            return ['count' => 0, 'average' => null, 'min' => null, 'max' => null, 'pass_rate' => null];
        }

        $passing = count(array_filter($scores, fn (float $s): bool => $s >= $passingGrade));

        return [
            'count' => count($scores),
            'average' => round(array_sum($scores) / count($scores), 2),
            'min' => round(min($scores), 2),
            'max' => round(max($scores), 2),
            'pass_rate' => round(($passing / count($scores)) * 100, 2),
        ];
    }

    /** The configured wording for a numeric average. */
    public function appreciationFor(?float $average, float $scaleMax = 100.0): ?string
    {
        if ($average === null) {
            return null;
        }

        // Thresholds are expressed on a 0–100 scale; rebase when the school
        // grades out of 20.
        $percentage = $scaleMax === 100.0 ? $average : ($average / $scaleMax) * 100;

        foreach (config('schoolflow.grading.appreciations', []) as $band) {
            if ($percentage >= $band['min']) {
                return $band['label'];
            }
        }

        return null;
    }
}
