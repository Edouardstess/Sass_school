<?php

declare(strict_types=1);

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\Grade;
use App\Domain\Academic\Services\GradeCalculator;

/**
 * The calculator is pure arithmetic over models, so these build unsaved
 * instances rather than touching the database — that keeps the rules under
 * test isolated from schema and tenancy concerns.
 */
function fakeGrade(?float $score, float $maxScore, float $weight, bool $absent = false): Grade
{
    $assessment = new Assessment;
    $assessment->forceFill(['max_score' => $maxScore, 'weight' => $weight]);

    $grade = new Grade;
    $grade->forceFill(['score' => $score, 'is_absent' => $absent]);
    $grade->setRelation('assessment', $assessment);

    return $grade;
}

it('averages a single mark on the assessment scale', function (): void {
    $calculator = new GradeCalculator;

    // 15/20 on a 100-point scale is 75.
    expect($calculator->subjectAverage(collect([fakeGrade(15, 20, 1)])))->toBe(75.0);
});

it('normalises different scales before combining them', function (): void {
    $calculator = new GradeCalculator;

    // 16/20 (= 80) and 60/100 (= 60), equal weights → 70.
    $average = $calculator->subjectAverage(collect([
        fakeGrade(16, 20, 1),
        fakeGrade(60, 100, 1),
    ]));

    expect($average)->toBe(70.0);
});

it('weights assessments by their declared weight', function (): void {
    $calculator = new GradeCalculator;

    // 50 with weight 1, 100 with weight 3 → (50 + 300) / 4 = 87.5
    expect($calculator->subjectAverage(collect([
        fakeGrade(50, 100, 1),
        fakeGrade(100, 100, 3),
    ])))->toBe(87.5);
});

it('excludes an absence instead of counting it as zero', function (): void {
    $calculator = new GradeCalculator;

    $average = $calculator->subjectAverage(collect([
        fakeGrade(80, 100, 1),
        fakeGrade(null, 100, 1, absent: true),
    ]));

    // Counting the absence as 0 would give 40. The student was ill, not wrong.
    expect($average)->toBe(80.0);
});

it('does count a genuine zero', function (): void {
    $calculator = new GradeCalculator;

    expect($calculator->subjectAverage(collect([
        fakeGrade(80, 100, 1),
        fakeGrade(0, 100, 1),
    ])))->toBe(40.0);
});

it('returns null rather than zero when nothing has been marked', function (): void {
    $calculator = new GradeCalculator;

    expect($calculator->subjectAverage(collect()))->toBeNull()
        ->and($calculator->subjectAverage(collect([fakeGrade(null, 100, 1, absent: true)])))->toBeNull();
});

it('weights subjects by coefficient for the overall average', function (): void {
    $calculator = new GradeCalculator;

    // (80×4 + 60×2) / 6 = 73.33
    expect($calculator->overallAverage([
        'maths' => ['average' => 80.0, 'coefficient' => 4.0],
        'history' => ['average' => 60.0, 'coefficient' => 2.0],
    ]))->toBe(73.33);
});

it('ignores unmarked subjects in the overall average rather than diluting it', function (): void {
    $calculator = new GradeCalculator;

    expect($calculator->overallAverage([
        'maths' => ['average' => 80.0, 'coefficient' => 4.0],
        'art' => ['average' => null, 'coefficient' => 2.0],
    ]))->toBe(80.0);
});

it('returns null when no subject has an average', function (): void {
    expect((new GradeCalculator)->overallAverage([
        'maths' => ['average' => null, 'coefficient' => 4.0],
    ]))->toBeNull();
});

it('never divides by zero on a malformed assessment', function (): void {
    $calculator = new GradeCalculator;

    expect($calculator->subjectAverage(collect([fakeGrade(10, 0, 1)])))->toBeNull()
        ->and($calculator->subjectAverage(collect([fakeGrade(10, 100, 0)])))->toBeNull();
});

it('maps averages onto the configured appreciation bands', function (): void {
    $calculator = new GradeCalculator;

    expect($calculator->appreciationFor(95))->toBe('Excellent')
        ->and($calculator->appreciationFor(72))->toBe('Bien')
        ->and($calculator->appreciationFor(20))->toBe('Insuffisant')
        ->and($calculator->appreciationFor(null))->toBeNull();
});

it('rebases appreciations when the school marks out of twenty', function (): void {
    $calculator = new GradeCalculator;

    // 18/20 is 90 % — "Excellent", not "Insuffisant".
    expect($calculator->appreciationFor(18, scaleMax: 20))->toBe('Excellent');
});
