<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Document\Models\Document;
use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof of a confirmed payment.
 *
 * `payment_id` is uniquely indexed: that constraint is precisely what makes
 * the issuing job safe to re-run after a crash or a duplicated webhook.
 */
class Receipt extends BaseModel
{
    use BelongsToTenant, HasMoneyColumns;

    protected $fillable = [
        'school_id', 'payment_id', 'invoice_id', 'student_id',
        'number', 'amount_minor', 'currency', 'issued_at', 'document_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
