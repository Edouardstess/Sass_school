<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\CurrencyMismatchException;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An immutable monetary amount held as an integer number of minor units.
 *
 * Floats are never used for money anywhere in SchoolFlow: 0.1 + 0.2 != 0.3 is
 * an accounting bug waiting to happen. All arithmetic here is integer
 * arithmetic, and operations between different currencies throw rather than
 * silently producing a meaningless number.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /**
     * Minor-unit exponent per supported currency. Adding a currency is a
     * matter of adding an entry here and to config/schoolflow.php.
     *
     * @var array<string, int>
     */
    private const EXPONENTS = [
        'HTG' => 2,
        'USD' => 2,
        'EUR' => 2,
        'CAD' => 2,
    ];

    public string $currency;

    public function __construct(public int $minorUnits, string $currency)
    {
        $currency = strtoupper($currency);

        if (! isset(self::EXPONENTS[$currency])) {
            throw new InvalidArgumentException("Unsupported currency [{$currency}].");
        }

        $this->currency = $currency;
    }

    public static function of(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Build from a decimal string such as "1234.50".
     *
     * A string, not a float, is accepted on purpose — passing a float here
     * would reintroduce exactly the precision loss this class exists to
     * prevent.
     */
    public static function fromDecimalString(string $amount, string $currency): self
    {
        $currency = strtoupper($currency);
        $exponent = self::EXPONENTS[$currency] ?? throw new InvalidArgumentException("Unsupported currency [{$currency}].");

        $amount = trim($amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException("[{$amount}] is not a valid decimal amount.");
        }

        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        // Round half-up on the first truncated digit rather than cutting it
        // off, so "0.005" becomes 1 centime and not 0.
        $roundUp = strlen($fraction) > $exponent && (int) $fraction[$exponent] >= 5;
        $fraction = str_pad(substr($fraction, 0, $exponent), $exponent, '0');

        $minor = (int) ($whole.$fraction) + ($roundUp ? 1 : 0);

        return new self($negative ? -$minor : $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->minorUnits * $factor, $this->currency);
    }

    /**
     * Apply a percentage expressed in basis points (2500 = 25.00 %).
     *
     * Uses intdiv on the rounded product so the result stays an exact integer
     * number of minor units.
     */
    public function percentage(int $basisPoints): self
    {
        $product = $this->minorUnits * $basisPoints;

        // Round half away from zero, matching how invoices are read by humans.
        $rounded = $product >= 0
            ? intdiv($product + 5_000, 10_000)
            : -intdiv(-$product + 5_000, 10_000);

        return new self($rounded, $this->currency);
    }

    /**
     * Split into `$parts` amounts whose sum is exactly this amount.
     *
     * The remainder is distributed one minor unit at a time to the earliest
     * parts, so no centime is created or lost when spreading a discount over
     * invoice lines.
     *
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot allocate money across fewer than one part.');
        }

        $base = intdiv($this->minorUnits, $parts);
        $remainder = $this->minorUnits - ($base * $parts);

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $extra = $i < abs($remainder) ? ($remainder <=> 0) : 0;
            $result[] = new self($base + $extra, $this->currency);
        }

        return $result;
    }

    /**
     * Split proportionally to the given integer weights, preserving the total.
     *
     * @param  list<int>  $weights
     * @return list<self>
     */
    public function allocateByWeights(array $weights): array
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return $this->allocateEvenly(max(count($weights), 1));
        }

        $allocated = [];
        $distributed = 0;

        foreach ($weights as $weight) {
            $share = intdiv($this->minorUnits * $weight, $total);
            $allocated[] = $share;
            $distributed += $share;
        }

        // Hand the rounding remainder to the largest weights first.
        $remainder = $this->minorUnits - $distributed;
        $order = array_keys($weights);
        usort($order, fn (int $a, int $b): int => $weights[$b] <=> $weights[$a]);

        $i = 0;
        while ($remainder !== 0 && $order !== []) {
            $index = $order[$i % count($order)];
            $step = $remainder <=> 0;
            $allocated[$index] += $step;
            $remainder -= $step;
            $i++;
        }

        return array_map(fn (int $minor): self => new self($minor, $this->currency), $allocated);
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits >= $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    /** The smaller of the two amounts; used to cap a payment at the balance due. */
    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <= $other->minorUnits ? $this : $other;
    }

    public function exponent(): int
    {
        return self::EXPONENTS[$this->currency];
    }

    /** Decimal representation without a thousands separator, e.g. "1234.50". */
    public function toDecimalString(): string
    {
        $exponent = $this->exponent();
        $negative = $this->minorUnits < 0;
        $digits = str_pad((string) abs($this->minorUnits), $exponent + 1, '0', STR_PAD_LEFT);

        $whole = substr($digits, 0, -$exponent);
        $fraction = substr($digits, -$exponent);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    public function format(?string $locale = null): string
    {
        $formatter = new \NumberFormatter($locale ?? 'fr_FR', \NumberFormatter::CURRENCY);

        return $formatter->formatCurrency((float) $this->toDecimalString(), $this->currency)
            ?: $this->toDecimalString().' '.$this->currency;
    }

    /**
     * The shape every API response uses for money. Both the exact integer and
     * a preformatted string are returned so the client never has to reimplement
     * currency arithmetic.
     *
     * @return array{minor_units: int, currency: string, amount: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minor_units' => $this->minorUnits,
            'currency' => $this->currency,
            'amount' => $this->toDecimalString(),
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->toDecimalString().' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException(
                "Cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }
}
