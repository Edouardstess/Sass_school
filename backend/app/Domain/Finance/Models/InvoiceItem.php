<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line on an invoice.
 *
 * `description` is denormalised from the fee type on purpose: renaming
 * "Scolarité" to "Frais de scolarité" must not silently rewrite invoices that
 * were already sent to parents.
 */
class InvoiceItem extends BaseModel
{
    use BelongsToTenant, HasMoneyColumns;

    protected $fillable = [
        'school_id', 'invoice_id', 'fee_type_id', 'description',
        'quantity', 'unit_price_minor', 'discount_minor', 'total_minor', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function unitPrice(): Money
    {
        return $this->money('unit_price_minor');
    }

    public function total(): Money
    {
        return $this->money('total_minor');
    }

    /** Line total before its own discount, used when spreading rebates. */
    public function grossTotal(): Money
    {
        return $this->unitPrice()->multiply($this->quantity);
    }
}
