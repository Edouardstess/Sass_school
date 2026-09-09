<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Academic\Models\Level;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A billable item: tuition, canteen, transport, exam fee…
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FeeType extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, HasMoneyColumns, SoftDeletes;

    public const RECURRENCE_ONE_TIME = 'one_time';

    public const RECURRENCE_MONTHLY = 'monthly';

    public const RECURRENCE_TERMLY = 'termly';

    public const RECURRENCE_YEARLY = 'yearly';

    protected $fillable = [
        'school_id', 'name', 'code', 'description', 'default_amount_minor',
        'currency', 'recurrence', 'level_id', 'is_mandatory', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_amount_minor' => 'integer',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function defaultAmount(): Money
    {
        return $this->money('default_amount_minor');
    }

    /** @return BelongsTo<Level, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== self::RECURRENCE_ONE_TIME;
    }
}
