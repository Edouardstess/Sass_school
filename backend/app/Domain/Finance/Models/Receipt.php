<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Document\Models\Document;
use App\Domain\School\Models\School;
use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof of a confirmed payment.
 *
 * `payment_id` is uniquely indexed: that constraint is precisely what makes
 * the issuing job safe to re-run after a crash or a duplicated webhook.
 */
/**
 * @property string $number
 * @property string|null $document_id
 * @property School|null $school
 * @property Payment|null $payment
 * @property Invoice|null $invoice
 * @property Student|null $student
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $updated_at
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

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
