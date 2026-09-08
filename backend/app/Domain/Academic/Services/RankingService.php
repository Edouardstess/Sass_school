<?php

declare(strict_types=1);

namespace App\Domain\Academic\Services;

use Illuminate\Support\Collection;

/**
 * Ranks students by average.
 *
 * Ties are handled with **standard competition ranking** ("1224"): students
 * with equal averages share the better rank, and the next distinct average
 * skips the positions consumed. Two students tied at the top are both 1st and
 * the next is 3rd — which is what a school expects, and what "gérer
 * correctement les égalités" means.
 *
 * Comparison happens on the rounded value actually printed on the report card.
 * Ranking on unrounded floats would produce cards showing two identical
 * averages with different ranks, which a parent reads — correctly — as a bug.
 */
final class RankingService
{
    /**
     * @param  array<string, float|null>  $averages  student id => average
     * @return array<string, int|null> student id => rank (null when unmarked)
     */
    public function rank(array $averages): array
    {
        $ranked = [];
        $unranked = [];

        foreach ($averages as $studentId => $average) {
            if ($average === null) {
                // A student with no marks at all is not ranked, rather than
                // being ranked last as though they had scored zero.
                $unranked[$studentId] = null;

                continue;
            }

            $ranked[$studentId] = round($average, 2);
        }

        arsort($ranked, SORT_NUMERIC);

        $result = [];
        $position = 0;
        $rank = 0;
        $previous = null;

        foreach ($ranked as $studentId => $average) {
            $position++;

            if ($previous === null || abs($average - $previous) >= 0.005) {
                // A new distinct average takes the current position, skipping
                // the places consumed by the tie above it.
                $rank = $position;
                $previous = $average;
            }

            $result[$studentId] = $rank;
        }

        return $result + $unranked;
    }

    /**
     * Aggregate statistics for a set of averages.
     *
     * @param  array<string, float|null>  $averages
     * @return array{count: int, ranked_count: int, average: float|null, min: float|null, max: float|null, pass_rate: float|null, top_student_id: string|null}
     */
    public function statistics(array $averages, float $passingGrade = 50.0): array
    {
        $values = array_filter($averages, fn (?float $a): bool => $a !== null);

        if ($values === []) {
            return [
                'count' => count($averages),
                'ranked_count' => 0,
                'average' => null,
                'min' => null,
                'max' => null,
                'pass_rate' => null,
                'top_student_id' => null,
            ];
        }

        $passing = count(array_filter($values, fn (float $a): bool => $a >= $passingGrade));
        $max = max($values);

        return [
            'count' => count($averages),
            'ranked_count' => count($values),
            'average' => round(array_sum($values) / count($values), 2),
            'min' => round(min($values), 2),
            'max' => round($max, 2),
            'pass_rate' => round(($passing / count($values)) * 100, 2),
            'top_student_id' => (string) array_search($max, $values, true),
        ];
    }

    /**
     * Rank within a single subject, so a report card can show "3rd of 28 in
     * mathematics" alongside the overall position.
     *
     * @param  Collection<string, float|null>  $subjectAverages
     * @return array<string, int|null>
     */
    public function rankBySubject(Collection $subjectAverages): array
    {
        return $this->rank($subjectAverages->all());
    }
}
