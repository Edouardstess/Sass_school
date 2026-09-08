<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\SubscriptionStatus;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's commercial agreement.
 *
 * `feature_overrides` is how an Enterprise tenant gets a bespoke ceiling
 * without a new plan row — the enforcer reads the override first and the plan
 * feature second.
 */
/**
 * @property string $id
 * @property string $school_id
 * @property string $plan_id
 * @property SubscriptionStatus $status
 * @property string $billing_cycle
 * @property array<string, mixed>|null $feature_overrides
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_end
 * @property Plan|null $plan
 */
class Subscription extends BaseModel
{
    protected $fillable = [
        'school_id', 'plan_id', 'status', 'billing_cycle', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancelled_at', 'ends_at',
        'feature_overrides',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'feature_overrides' => 'array',
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && $this->trial_ends_at?->isFuture() === true;
    }

    public function hasExpired(): bool
    {
        return $this->current_period_end !== null && $this->current_period_end->isPast();
    }

    public function monthlyRecurringRevenue(): Money
    {
        if (! $this->status->isBillable() || $this->plan === null) {
            return Money::zero($this->plan->currency ?? 'USD');
        }

        return $this->plan->monthlyRecurringRevenue($this->billing_cycle);
    }

    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::PastDue->value,
        ]);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SubscriptionStatus::Trialing->value,
            SubscriptionStatus::Active->value,
            SubscriptionStatus::PastDue->value,
        ]);
    }
}
