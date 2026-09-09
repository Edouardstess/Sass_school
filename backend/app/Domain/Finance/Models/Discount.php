<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * A reusable rebate.
 *
 * Percentages are stored in basis points (2500 = 25.00 %) so no float is ever
 * involved in computing what a family owes.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Discount extends BaseModel
{
    use BelongsToTenant;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = ['school_id', 'name', 'code', 'type', 'value', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'is_active' => 'boolean'];
    }

    /** The amount this discount removes from the given base. */
    public function amountFor(Money $base): Money
    {
        return $this->type === self::TYPE_PERCENTAGE
            ? $base->percentage($this->value)
            : Money::of($this->value, $this->currency ?? $base->currency)->min($base);
    }
}
