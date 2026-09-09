<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Concerns\HasMoneyColumns;
use App\Domain\Shared\Enums\InvoiceStatus;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Guardian;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bill issued to a student's financially responsible guardian.
 *
 * Totals are maintained by InvoiceService inside a transaction and checked by
 * a database CHECK constraint (`balance = total - paid`), so the row can never
 * drift into an arithmetically impossible state — not through a race, not
 * through a partially applied update.
 */
/**
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $number
 * @property string $currency
 * @property InvoiceStatus $status
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property int $paid_minor
 * @property int $balance_minor
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $paid_at
 * @property Collection<int, InvoiceItem> $items
 * @property Student|null $student
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Invoice extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, HasMoneyColumns, SoftDeletes;

    protected $fillable = [
        'school_id', 'student_id', 'academic_year_id', 'guardian_id', 'created_by',
        'number', 'currency', 'subtotal_minor', 'discount_minor', 'total_minor',
        'paid_minor', 'balance_minor', 'status', 'issued_on', 'due_on',
        'notes', 'generation_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'balance_minor' => 'integer',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    // ----------------------------------------------------------------- money

    public function subtotal(): Money
    {
        return $this->money('subtotal_minor');
    }

    public function discount(): Money
    {
        return $this->money('discount_minor');
    }

    public function total(): Money
    {
        return $this->money('total_minor');
    }

    public function paid(): Money
    {
        return $this->money('paid_minor');
    }

    public function balance(): Money
    {
        return $this->money('balance_minor');
    }

    // ----------------------------------------------------------------- state

    public function isOverdue(?CarbonImmutable $asOf = null): bool
    {
        $asOf ??= CarbonImmutable::now();

        return $this->status->isOutstanding()
            && $this->due_on !== null
            && $this->due_on->isBefore($asOf->startOfDay());
    }

    /** Days past due; negative when the invoice is not yet due. */
    public function daysOverdue(?CarbonImmutable $asOf = null): ?int
    {
        if ($this->due_on === null) {
            return null;
        }

        return (int) $this->due_on->startOfDay()->diffInDays(($asOf ?? CarbonImmutable::now())->startOfDay(), false);
    }

    // ------------------------------------------------------------ relations

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Only the payments that actually moved the balance. */
    public function successfulPayments(): HasMany
    {
        return $this->payments()->where('status', 'succeeded');
    }

    /** @return HasMany<Receipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    // --------------------------------------------------------------- scopes

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Issued->value,
            InvoiceStatus::PartiallyPaid->value,
            InvoiceStatus::Overdue->value,
        ]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $this->scopeOutstanding($query)->whereDate('due_on', '<', now()->toDateString());
    }
}
