<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The provider-facing half of a payment attempt.
 *
 * Kept apart from `payments` so the accounting record is never polluted by
 * abandoned checkouts, and so the finance domain never needs to know that a
 * gateway exists.
 */
class PaymentTransaction extends BaseModel
{
    use BelongsToTenant, HasMoneyColumns;

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'school_id', 'payment_id', 'invoice_id', 'provider', 'provider_reference',
        'amount_minor', 'currency', 'status', 'checkout_url', 'expires_at',
        'provider_payload', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'provider_payload' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
