<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Enums\PaymentMethod;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money received against an invoice.
 *
 * Only a payment in `succeeded` moves the invoice balance. Gateway payments
 * reach that state exclusively through a verified webhook — never because a
 * browser was redirected to a success URL.
 */
/**
 * @property string $id
 * @property string $school_id
 * @property string $invoice_id
 * @property string $student_id
 * @property string $reference
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property CarbonImmutable|null $paid_at
 * @property Invoice|null $invoice
 * @property Student|null $student
 */
class Payment extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, HasMoneyColumns, SoftDeletes;

    protected $fillable = [
        'school_id', 'invoice_id', 'student_id', 'recorded_by', 'reference',
        'amount_minor', 'currency', 'method', 'status', 'paid_at',
        'payer_name', 'payer_phone', 'external_reference', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function amount(): Money
    {
        return $this->money('amount_minor');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Succeeded->value);
    }

    /** Amount already refunded, so a second refund cannot exceed the payment. */
    public function refundedAmount(): Money
    {
        $minor = (int) $this->refunds()
            ->whereIn('status', ['approved', 'processed'])
            ->sum('amount_minor');

        return Money::of($minor, $this->currency);
    }

    public function refundableAmount(): Money
    {
        return $this->amount()->subtract($this->refundedAmount());
    }
}
