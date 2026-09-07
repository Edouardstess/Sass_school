<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A priced line inside a subscription (base fee, add-on bundles). */
class SubscriptionItem extends BaseModel
{
    use HasMoneyColumns;

    protected $fillable = [
        'subscription_id', 'key', 'label', 'quantity', 'unit_price_minor', 'currency',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function total(): Money
    {
        return $this->money('unit_price_minor')->multiply($this->quantity);
    }
}
