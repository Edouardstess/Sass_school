<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use App\Domain\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A daily measurement of one metered metric for one tenant.
 *
 * History is kept rather than a single running total, so usage graphs show
 * what actually happened instead of an extrapolation.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $recorded_on
 * @property CarbonImmutable|null $updated_at
 */
class UsageRecord extends BaseModel
{
    protected $fillable = ['school_id', 'subscription_id', 'metric', 'value', 'recorded_on'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'recorded_on' => 'immutable_date'];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
