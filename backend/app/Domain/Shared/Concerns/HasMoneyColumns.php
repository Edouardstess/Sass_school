<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\ValueObjects\Money;

/**
 * Reads a `*_minor` integer column plus the row's `currency` column back as a
 * Money value object, so services never handle raw integers and can never
 * accidentally add HTG to USD.
 *
 * Models list their money columns in `$moneyColumns`:
 *   protected array $moneyColumns = ['total_minor' => 'total'];
 */
trait HasMoneyColumns
{
    public function money(string $column): Money
    {
        return Money::of(
            (int) $this->getAttribute($column),
            (string) ($this->getAttribute($this->currencyColumn()) ?? config('schoolflow.currency.default'))
        );
    }

    public function setMoney(string $column, Money $money): static
    {
        $this->setAttribute($column, $money->minorUnits);
        $this->setAttribute($this->currencyColumn(), $money->currency);

        return $this;
    }

    /**
     * Money columns rendered alongside the raw integers in API resources.
     *
     * @return array<string, string> minor column => exposed name
     */
    public function moneyColumns(): array
    {
        return property_exists($this, 'moneyColumns') ? $this->moneyColumns : [];
    }

    protected function currencyColumn(): string
    {
        return 'currency';
    }
}
