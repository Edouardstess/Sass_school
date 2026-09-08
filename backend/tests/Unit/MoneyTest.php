<?php

declare(strict_types=1);

use App\Domain\Shared\Exceptions\CurrencyMismatchException;
use App\Domain\Shared\ValueObjects\Money;

it('parses decimal strings without floating point loss', function (): void {
    expect(Money::fromDecimalString('1234.50', 'HTG'))->toBeMoney(123_450, 'HTG')
        ->and(Money::fromDecimalString('0.01', 'USD'))->toBeMoney(1, 'USD')
        ->and(Money::fromDecimalString('1000', 'HTG'))->toBeMoney(100_000, 'HTG')
        ->and(Money::fromDecimalString('-45.99', 'USD'))->toBeMoney(-4_599, 'USD');
});

it('rounds half up on the first truncated digit rather than discarding it', function (): void {
    // 0.005 must become one centime, not zero.
    expect(Money::fromDecimalString('0.005', 'USD'))->toBeMoney(1, 'USD')
        ->and(Money::fromDecimalString('0.004', 'USD'))->toBeMoney(0, 'USD')
        ->and(Money::fromDecimalString('10.999', 'HTG'))->toBeMoney(1_100, 'HTG');
});

it('survives the classic float trap', function (): void {
    // 0.1 + 0.2 !== 0.3 in binary floating point. In minor units it is exact.
    $sum = Money::fromDecimalString('0.10', 'USD')->add(Money::fromDecimalString('0.20', 'USD'));

    expect($sum)->toBeMoney(30, 'USD')
        ->and($sum->equals(Money::fromDecimalString('0.30', 'USD')))->toBeTrue();
});

it('refuses arithmetic across currencies', function (): void {
    Money::of(100, 'HTG')->add(Money::of(100, 'USD'));
})->throws(CurrencyMismatchException::class);

it('applies percentages given in basis points', function (): void {
    $amount = Money::of(10_000, 'HTG');   // 100.00 HTG

    expect($amount->percentage(2_500))->toBeMoney(2_500, 'HTG')      // 25 %
        ->and($amount->percentage(10_000))->toBeMoney(10_000, 'HTG') // 100 %
        ->and($amount->percentage(1_250))->toBeMoney(1_250, 'HTG');  // 12.5 %
});

it('rounds percentages half away from zero', function (): void {
    // 33.33 % of 1 centime rounds to 0; 50 % of 3 rounds to 2, not 1.
    expect(Money::of(3, 'USD')->percentage(5_000))->toBeMoney(2, 'USD')
        ->and(Money::of(1, 'USD')->percentage(3_333))->toBeMoney(0, 'USD');
});

it('allocates evenly without creating or losing a single minor unit', function (): void {
    $parts = Money::of(100, 'HTG')->allocateEvenly(3);

    expect($parts)->toHaveCount(3)
        ->and(array_sum(array_map(fn (Money $m): int => $m->minorUnits, $parts)))->toBe(100)
        // The remainder goes to the earliest parts: 34, 33, 33.
        ->and($parts[0])->toBeMoney(34, 'HTG')
        ->and($parts[1])->toBeMoney(33, 'HTG')
        ->and($parts[2])->toBeMoney(33, 'HTG');
});

it('allocates by weight and still preserves the total exactly', function (): void {
    // A discount spread across three invoice lines of unequal size.
    $parts = Money::of(1_000, 'HTG')->allocateByWeights([3_333, 3_333, 3_334]);

    expect(array_sum(array_map(fn (Money $m): int => $m->minorUnits, $parts)))->toBe(1_000);
});

it('preserves the total when weights do not divide evenly', function (): void {
    foreach ([[1, 1, 1], [7, 2, 1], [1], [5, 5]] as $weights) {
        $parts = Money::of(9_999, 'USD')->allocateByWeights($weights);

        expect(array_sum(array_map(fn (Money $m): int => $m->minorUnits, $parts)))->toBe(9_999);
    }
});

it('falls back to an even split when every weight is zero', function (): void {
    $parts = Money::of(10, 'USD')->allocateByWeights([0, 0]);

    expect(array_sum(array_map(fn (Money $m): int => $m->minorUnits, $parts)))->toBe(10);
});

it('formats back to a decimal string losslessly', function (): void {
    expect(Money::of(123_450, 'HTG')->toDecimalString())->toBe('1234.50')
        ->and(Money::of(5, 'USD')->toDecimalString())->toBe('0.05')
        ->and(Money::of(0, 'USD')->toDecimalString())->toBe('0.00')
        ->and(Money::of(-4_599, 'USD')->toDecimalString())->toBe('-45.99');
});

it('caps at the smaller amount with min()', function (): void {
    expect(Money::of(500, 'HTG')->min(Money::of(300, 'HTG')))->toBeMoney(300, 'HTG')
        ->and(Money::of(100, 'HTG')->min(Money::of(300, 'HTG')))->toBeMoney(100, 'HTG');
});

it('rejects an unsupported currency', function (): void {
    Money::of(100, 'XYZ');
})->throws(InvalidArgumentException::class);

it('serialises with both the exact integer and a human-readable form', function (): void {
    $json = Money::of(123_450, 'HTG')->jsonSerialize();

    expect($json['minor_units'])->toBe(123_450)
        ->and($json['currency'])->toBe('HTG')
        ->and($json['amount'])->toBe('1234.50')
        ->and($json['formatted'])->toBeString();
});
