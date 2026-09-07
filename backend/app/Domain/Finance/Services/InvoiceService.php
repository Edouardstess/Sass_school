<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\FeeType;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\InvoiceItem;
use App\Domain\Finance\Models\Scholarship;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Enums\InvoiceStatus;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Services\NumberGenerator;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds and maintains invoices.
 *
 * Two invariants are upheld here and checked again by the database:
 *   total   = subtotal − discount
 *   balance = total − paid
 *
 * Every mutation recomputes both from the rows that actually exist rather than
 * adjusting a running figure, so a partially applied update cannot leave the
 * invoice quietly wrong.
 */
final class InvoiceService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a draft invoice.
     *
     * @param  list<array{fee_type_id?: string|null, description: string, quantity?: int, unit_price_minor: int, discount_minor?: int}>  $items
     */
    public function create(
        Student $student,
        string $academicYearId,
        array $items,
        ?CarbonImmutable $dueOn = null,
        ?string $currency = null,
        ?string $notes = null,
        ?string $generationKey = null,
        ?string $createdBy = null,
    ): Invoice {
        if ($items === []) {
            throw new DomainException(__('finance.invoice_requires_items'));
        }

        $currency ??= $student->school?->currency ?? config('schoolflow.currency.default');

        return DB::transaction(function () use (
            $student, $academicYearId, $items, $dueOn, $currency, $notes, $generationKey, $createdBy
        ): Invoice {
            $invoice = new Invoice;
            $invoice->forceFill([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'academic_year_id' => $academicYearId,
                'guardian_id' => $student->relationLoaded('guardians')
                    ? $student->financialGuardian()?->id
                    : $student->guardians()->first()?->id,
                'created_by' => $createdBy,
                'number' => $this->numbers->next('invoice', null, $student->school_id),
                'currency' => $currency,
                'status' => InvoiceStatus::Draft->value,
                'notes' => $notes,
                'generation_key' => $generationKey,
                'subtotal_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 0,
                'paid_minor' => 0,
                'balance_minor' => 0,
            ])->save();

            foreach ($items as $item) {
                $this->addItemRow($invoice, $item, $currency);
            }

            $this->recalculate($invoice);

            $this->audit->log(AuditAction::Create, $invoice, [
                'description' => "Invoice {$invoice->number} drafted for {$student->full_name}",
                'new_values' => $invoice->only(['number', 'total_minor', 'currency']),
            ]);

            return $invoice->fresh(['items']);
        });
    }

    /**
     * Build the standard set of lines for a student from the school's active
     * fee types, applying any scholarship the student holds.
     *
     * @return list<array{fee_type_id: string, description: string, quantity: int, unit_price_minor: int, discount_minor: int}>
     */
    public function buildStandardItems(Student $student, ?string $levelId = null, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::now();

        $feeTypes = FeeType::query()
            ->active()
            ->where(fn ($q) => $q->whereNull('level_id')->when($levelId !== null, fn ($q2) => $q2->orWhere('level_id', $levelId)))
            ->orderBy('name')
            ->get();

        $scholarships = Scholarship::query()
            ->where('student_id', $student->id)
            ->effectiveOn($on)
            ->get();

        $items = [];

        foreach ($feeTypes as $feeType) {
            $gross = $feeType->defaultAmount();

            if ($gross->isZero()) {
                continue;
            }

            // Scholarships stack additively but can never take a line below
            // zero — a 60 % and a 50 % award means free, not a credit.
            $reduction = Money::zero($gross->currency);

            foreach ($scholarships as $scholarship) {
                $reduction = $reduction->add($scholarship->amountFor($gross));
            }

            $reduction = $reduction->min($gross);

            $items[] = [
                'fee_type_id' => $feeType->id,
                'description' => $feeType->name,
                'quantity' => 1,
                'unit_price_minor' => $gross->minorUnits,
                'discount_minor' => $reduction->minorUnits,
            ];
        }

        return $items;
    }

    /** @param array{fee_type_id?: string|null, description: string, quantity?: int, unit_price_minor: int, discount_minor?: int} $item */
    public function addItem(Invoice $invoice, array $item): InvoiceItem
    {
        $this->assertEditable($invoice);

        return DB::transaction(function () use ($invoice, $item): InvoiceItem {
            $row = $this->addItemRow($invoice, $item, $invoice->currency);
            $this->recalculate($invoice);

            return $row;
        });
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item): void
    {
        $this->assertEditable($invoice);

        DB::transaction(function () use ($invoice, $item): void {
            $item->delete();
            $this->recalculate($invoice);
        });
    }

    /**
     * Apply an invoice-level discount, spread proportionally across the lines
     * so each line's share is exact and the parts still sum to the whole.
     */
    public function applyDiscount(Invoice $invoice, Discount $discount): Invoice
    {
        $this->assertEditable($invoice);

        return DB::transaction(function () use ($invoice, $discount): Invoice {
            $invoice->load('items');

            $gross = $invoice->items->reduce(
                fn (Money $carry, InvoiceItem $item): Money => $carry->add($item->grossTotal()),
                Money::zero($invoice->currency),
            );

            $reduction = $discount->amountFor($gross)->min($gross);

            $weights = $invoice->items->map(fn (InvoiceItem $i): int => $i->grossTotal()->minorUnits)->all();
            $shares = $reduction->allocateByWeights($weights);

            foreach ($invoice->items as $index => $item) {
                $share = $shares[$index] ?? Money::zero($invoice->currency);
                $item->forceFill([
                    'discount_minor' => $share->minorUnits,
                    'total_minor' => $item->grossTotal()->subtract($share)->minorUnits,
                ])->save();
            }

            $this->recalculate($invoice);

            $this->audit->log(AuditAction::Update, $invoice, [
                'description' => "Discount [{$discount->name}] applied to invoice {$invoice->number}",
            ]);

            return $invoice->fresh(['items']);
        });
    }

    /** Move a draft to `issued`, which is what makes it payable and visible. */
    public function issue(Invoice $invoice, ?CarbonImmutable $dueOn = null): Invoice
    {
        if (! $invoice->status->isEditable()) {
            throw new DomainException(__('finance.invoice_already_issued', ['number' => $invoice->number]));
        }

        if ($invoice->items()->count() === 0) {
            throw new DomainException(__('finance.invoice_requires_items'));
        }

        $invoice->forceFill([
            'status' => InvoiceStatus::Issued->value,
            'issued_on' => CarbonImmutable::now()->toDateString(),
            'due_on' => ($dueOn ?? $invoice->due_on ?? CarbonImmutable::now()->addDays(30))->toDateString(),
        ])->save();

        $this->audit->log(AuditAction::Update, $invoice, [
            'description' => "Invoice {$invoice->number} issued",
            'new_values' => ['status' => InvoiceStatus::Issued->value],
        ]);

        return $invoice;
    }

    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status->isFinal()) {
            throw new DomainException(__('finance.invoice_not_cancellable'));
        }

        // Cancelling an invoice that has taken money would silently destroy
        // the accounting trail; the money must be refunded first.
        if ($invoice->paid_minor > 0) {
            throw new DomainException(__('finance.invoice_has_payments'));
        }

        $invoice->forceFill([
            'status' => InvoiceStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'balance_minor' => 0,
        ])->save();

        $this->audit->log(AuditAction::Update, $invoice, [
            'description' => "Invoice {$invoice->number} cancelled: {$reason}",
        ]);

        return $invoice;
    }

    /**
     * Recompute totals from the invoice's own rows and re-derive the status.
     *
     * Called after every mutation, including after a payment is applied. It is
     * the single place invoice arithmetic happens.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        $items = $invoice->items()->get();

        $subtotal = $items->reduce(
            fn (Money $carry, InvoiceItem $item): Money => $carry->add($item->grossTotal()),
            Money::zero($invoice->currency),
        );

        $discount = $items->reduce(
            fn (Money $carry, InvoiceItem $item): Money => $carry->add($item->money('discount_minor')),
            Money::zero($invoice->currency),
        );

        $total = $subtotal->subtract($discount);

        // Only settled money counts. A pending gateway attempt must not make
        // an invoice look paid.
        $paidMinor = (int) $invoice->successfulPayments()->sum('amount_minor');
        $refundedMinor = (int) $invoice->refunds()
            ->whereIn('status', ['approved', 'processed'])
            ->sum('amount_minor');

        $paid = Money::of(max(0, $paidMinor - $refundedMinor), $invoice->currency);
        $balance = $total->subtract($paid);

        $invoice->forceFill([
            'subtotal_minor' => $subtotal->minorUnits,
            'discount_minor' => $discount->minorUnits,
            'total_minor' => $total->minorUnits,
            'paid_minor' => $paid->minorUnits,
            'balance_minor' => $balance->minorUnits,
            'status' => $this->deriveStatus($invoice, $total, $paid)->value,
            'paid_at' => $balance->isPositive() ? null : ($invoice->paid_at ?? now()),
        ])->save();

        return $invoice;
    }

    /**
     * Status implied by the money, respecting terminal states.
     *
     * Draft and cancelled invoices are never re-derived: a cancelled invoice
     * that somehow received a payment must be dealt with by a human, not
     * silently resurrected.
     */
    private function deriveStatus(Invoice $invoice, Money $total, Money $paid): InvoiceStatus
    {
        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled], true)) {
            return $invoice->status;
        }

        if ($paid->greaterThanOrEqual($total) && ! $total->isZero()) {
            return InvoiceStatus::Paid;
        }

        if ($total->isZero()) {
            return InvoiceStatus::Paid;
        }

        if ($paid->isPositive()) {
            return $invoice->isOverdue() ? InvoiceStatus::Overdue : InvoiceStatus::PartiallyPaid;
        }

        return $invoice->isOverdue() ? InvoiceStatus::Overdue : InvoiceStatus::Issued;
    }

    /** @param array{fee_type_id?: string|null, description: string, quantity?: int, unit_price_minor: int, discount_minor?: int} $item */
    private function addItemRow(Invoice $invoice, array $item, string $currency): InvoiceItem
    {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitPrice = Money::of((int) $item['unit_price_minor'], $currency);
        $gross = $unitPrice->multiply($quantity);
        $discount = Money::of((int) ($item['discount_minor'] ?? 0), $currency)->min($gross);

        $row = new InvoiceItem;
        $row->forceFill([
            'school_id' => $invoice->school_id,
            'invoice_id' => $invoice->id,
            'fee_type_id' => $item['fee_type_id'] ?? null,
            'description' => $item['description'],
            'quantity' => $quantity,
            'unit_price_minor' => $unitPrice->minorUnits,
            'discount_minor' => $discount->minorUnits,
            'total_minor' => $gross->subtract($discount)->minorUnits,
            'currency' => $currency,
        ])->save();

        return $row;
    }

    private function assertEditable(Invoice $invoice): void
    {
        if (! $invoice->status->isEditable()) {
            throw new DomainException(__('finance.invoice_not_editable', ['number' => $invoice->number]));
        }
    }
}
