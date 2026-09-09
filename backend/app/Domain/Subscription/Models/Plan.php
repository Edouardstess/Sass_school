<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A commercial tier. Limits live in `features`, never in code. */
/**
 * @property string $id
 * @property string $code
 * @property string $currency
 * @property int $price_monthly_minor
 * @property int $price_yearly_minor
 * @property Collection<int, PlanFeature> $features
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Plan extends BaseModel
{
    use HasMoneyColumns;

    public const STARTER = 'starter';

    public const STANDARD = 'standard';

    public const PROFESSIONAL = 'professional';

    public const ENTERPRISE = 'enterprise';

    protected $fillable = [
        'code', 'name', 'description', 'price_monthly_minor', 'price_yearly_minor',
        'currency', 'trial_days', 'sort_order', 'is_active', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly_minor' => 'integer',
            'price_yearly_minor' => 'integer',
            'trial_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function monthlyPrice(): Money
    {
        return $this->money('price_monthly_minor');
    }

    public function yearlyPrice(): Money
    {
        return $this->money('price_yearly_minor');
    }

    public function priceFor(string $cycle): Money
    {
        return $cycle === 'yearly' ? $this->yearlyPrice() : $this->monthlyPrice();
    }

    /** Normalised monthly value, so yearly plans contribute correctly to MRR. */
    public function monthlyRecurringRevenue(string $cycle): Money
    {
        return $cycle === 'yearly'
            ? Money::of(intdiv($this->price_yearly_minor, 12), $this->currency)
            : $this->monthlyPrice();
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }
}
