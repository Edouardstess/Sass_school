<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use App\Domain\Shared\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A daily measurement of one metered metric for one tenant.
 *
 * History is kept rather than a single running total, so usage graphs show
 * what actually happened instead of an extrapolation.
 */
class UsageRecord extends BaseModel
{
    protected $fillable = ['school_id', 'subscription_id', 'metric', 'value', 'recorded_on'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'recorded_on' => 'immutable_date'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
