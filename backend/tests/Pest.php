<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case bindings
|--------------------------------------------------------------------------
| Every suite runs against a real PostgreSQL database inside a transaction
| that is rolled back afterwards, so tests exercise the actual constraints,
| partial indexes and rules the production schema relies on.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Security');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Custom expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeMoney', function (int $minorUnits, string $currency) {
    expect($this->value->minorUnits)->toBe($minorUnits)
        ->and($this->value->currency)->toBe($currency);

    return $this;
});

/** Asserts the standard success envelope. */
expect()->extend('toBeSuccessfulApiResponse', function () {
    $this->value->assertOk()->assertJsonPath('success', true);

    return $this;
});
