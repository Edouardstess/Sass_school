<?php

declare(strict_types=1);

use App\Domain\Academic\Services\RankingService;

it('ranks students from the highest average down', function (): void {
    $ranks = (new RankingService)->rank(['a' => 55.0, 'b' => 90.0, 'c' => 72.0]);

    expect($ranks)->toBe(['b' => 1, 'c' => 2, 'a' => 3]);
});

it('gives tied students the same rank and skips the positions they consume', function (): void {
    // Standard competition ranking: 1, 1, 3 — not 1, 1, 2.
    $ranks = (new RankingService)->rank(['a' => 90.0, 'b' => 90.0, 'c' => 75.0]);

    expect($ranks['a'])->toBe(1)
        ->and($ranks['b'])->toBe(1)
        ->and($ranks['c'])->toBe(3);
});

it('handles a three-way tie', function (): void {
    $ranks = (new RankingService)->rank(['a' => 80.0, 'b' => 80.0, 'c' => 80.0, 'd' => 60.0]);

    expect($ranks['a'])->toBe(1)
        ->and($ranks['b'])->toBe(1)
        ->and($ranks['c'])->toBe(1)
        ->and($ranks['d'])->toBe(4);
});

it('treats averages that print identically as tied', function (): void {
    // 80.001 and 80.004 both render as 80.00 on the report card, so showing
    // them with different ranks would read as a bug to a parent.
    $ranks = (new RankingService)->rank(['a' => 80.001, 'b' => 80.004]);

    expect($ranks['a'])->toBe(1)->and($ranks['b'])->toBe(1);
});

it('leaves an unmarked student unranked rather than ranking them last', function (): void {
    $ranks = (new RankingService)->rank(['a' => 90.0, 'b' => null]);

    expect($ranks['a'])->toBe(1)
        ->and($ranks['b'])->toBeNull();
});

it('computes class statistics over the marked students only', function (): void {
    $stats = (new RankingService)->statistics(['a' => 90.0, 'b' => 40.0, 'c' => 70.0, 'd' => null]);

    expect($stats['count'])->toBe(4)
        ->and($stats['ranked_count'])->toBe(3)
        ->and($stats['average'])->toBe(66.67)
        ->and($stats['min'])->toBe(40.0)
        ->and($stats['max'])->toBe(90.0)
        ->and($stats['pass_rate'])->toBe(66.67)
        ->and($stats['top_student_id'])->toBe('a');
});

it('reports empty statistics without dividing by zero', function (): void {
    $stats = (new RankingService)->statistics(['a' => null]);

    expect($stats['ranked_count'])->toBe(0)
        ->and($stats['average'])->toBeNull()
        ->and($stats['pass_rate'])->toBeNull();
});
