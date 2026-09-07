<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends BaseModel
{
    use BelongsToTenant, HasMoneyColumns;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'school_id', 'payment_id', 'invoice_id', 'approved_by', 'reference',
        'amount_minor', 'currency', 'reason', 'status', 'processed_at', 'external_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function amount(): Money
    {
        return $this->money('amount_minor');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
