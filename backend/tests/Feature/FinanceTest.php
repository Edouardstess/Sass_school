<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Receipt;
use App\Domain\Finance\Services\InvoiceService;
use App\Domain\Finance\Services\PaymentService;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\InvoiceStatus;
use App\Domain\Shared\Enums\PaymentMethod;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Student;

beforeEach(function (): void {
    $this->school = $this->makeSchool(['currency' => 'HTG']);
    $this->year = AcademicYear::query()->where('school_id', $this->school->id)->firstOrFail();

    $this->student = $this->withinTenant(
        $this->school,
        fn () => Student::factory()->create(['school_id' => $this->school->id]),
    );

    $this->invoices = app(InvoiceService::class);
    $this->payments = app(PaymentService::class);
});

/** @return array{0: Invoice} */
function makeIssuedInvoice($test, int $unitPriceMinor = 100_000): Invoice
{
    return $test->withinTenant($test->school, function () use ($test, $unitPriceMinor): Invoice {
        $invoice = $test->invoices->create(
            student: $test->student,
            academicYearId: $test->year->id,
            items: [[
                'description' => 'Scolarité',
                'quantity' => 1,
                'unit_price_minor' => $unitPriceMinor,
            ]],
            currency: 'HTG',
        );

        return $test->invoices->issue($invoice);
    });
}

// ------------------------------------------------------------------ invoices

it('creates a draft invoice with correct totals', function (): void {
    $invoice = $this->withinTenant($this->school, fn () => $this->invoices->create(
        student: $this->student,
        academicYearId: $this->year->id,
        items: [
            ['description' => 'Scolarité', 'quantity' => 1, 'unit_price_minor' => 250_000],
            ['description' => 'Cantine', 'quantity' => 3, 'unit_price_minor' => 80_000, 'discount_minor' => 20_000],
        ],
        currency: 'HTG',
    ));

    // 250 000 + (3 × 80 000) = 490 000 gross, less a 20 000 line discount.
    expect($invoice->subtotal_minor)->toBe(490_000)
        ->and($invoice->discount_minor)->toBe(20_000)
        ->and($invoice->total_minor)->toBe(470_000)
        ->and($invoice->balance_minor)->toBe(470_000)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toStartWith('INV-');
});

it('refuses an invoice with no lines', function (): void {
    $this->withinTenant($this->school, fn () => $this->invoices->create(
        student: $this->student,
        academicYearId: $this->year->id,
        items: [],
    ));
})->throws(DomainException::class);

it('mints sequential, non-colliding invoice numbers', function (): void {
    $numbers = $this->withinTenant($this->school, function (): array {
        $out = [];

        foreach (range(1, 3) as $ignored) {
            $out[] = $this->invoices->create(
                student: $this->student,
                academicYearId: $this->year->id,
                items: [['description' => 'X', 'unit_price_minor' => 1_000]],
            )->number;
        }

        return $out;
    });

    expect($numbers)->toHaveCount(3)
        ->and(array_unique($numbers))->toHaveCount(3)
        ->and($numbers[0])->toEndWith('000001')
        ->and($numbers[2])->toEndWith('000003');
});

it('will not edit an invoice once issued', function (): void {
    $invoice = makeIssuedInvoice($this);

    $this->withinTenant($this->school, fn () => $this->invoices->addItem($invoice, [
        'description' => 'Sneaky', 'unit_price_minor' => 1,
    ]));
})->throws(DomainException::class);

// ------------------------------------------------------------------ payments

it('applies a full payment and settles the invoice', function (): void {
    $invoice = makeIssuedInvoice($this, 100_000);

    $payment = $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(100_000, 'HTG'),
        method: PaymentMethod::Cash,
    ));

    $invoice->refresh();

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->paid_minor)->toBe(100_000)
        ->and($invoice->balance_minor)->toBe(0)
        ->and($invoice->paid_at)->not->toBeNull();
});

it('applies a partial payment and leaves the balance outstanding', function (): void {
    $invoice = makeIssuedInvoice($this, 100_000);

    $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(30_000, 'HTG'),
        method: PaymentMethod::BankTransfer,
    ));

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoice->paid_minor)->toBe(30_000)
        ->and($invoice->balance_minor)->toBe(70_000);
});

it('refuses a payment larger than the outstanding balance', function (): void {
    $invoice = makeIssuedInvoice($this, 100_000);

    $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(150_000, 'HTG'),
        method: PaymentMethod::Cash,
    ));
})->throws(DomainException::class);

it('refuses a payment in a different currency from the invoice', function (): void {
    $invoice = makeIssuedInvoice($this, 100_000);

    $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(1_000, 'USD'),
        method: PaymentMethod::Cash,
    ));
})->throws(DomainException::class);

it('refuses a payment against a draft invoice', function (): void {
    $invoice = $this->withinTenant($this->school, fn () => $this->invoices->create(
        student: $this->student,
        academicYearId: $this->year->id,
        items: [['description' => 'X', 'unit_price_minor' => 5_000]],
    ));

    $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(5_000, 'HTG'),
        method: PaymentMethod::Cash,
    ));
})->throws(DomainException::class);

it('refuses to record a gateway method as a manual payment', function (): void {
    $invoice = makeIssuedInvoice($this);

    // Otherwise anyone with payments.create could mark a MonCash payment
    // received without MonCash ever confirming it.
    $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(1_000, 'HTG'),
        method: PaymentMethod::MonCash,
    ));
})->throws(DomainException::class);

// ------------------------------------------------------------------ receipts

it('issues exactly one receipt per payment', function (): void {
    $invoice = makeIssuedInvoice($this, 50_000);

    $payment = $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(50_000, 'HTG'),
        method: PaymentMethod::Cash,
    ));

    $receipts = Receipt::query()->withoutTenantScope()->where('payment_id', $payment->id)->get();

    expect($receipts)->toHaveCount(1)
        ->and($receipts->first()->number)->toStartWith('REC-')
        ->and($receipts->first()->amount_minor)->toBe(50_000);
});

// ------------------------------------------------------------------- refunds

it('reopens the balance when a payment is refunded', function (): void {
    $invoice = makeIssuedInvoice($this, 100_000);

    $payment = $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(100_000, 'HTG'),
        method: PaymentMethod::Cash,
    ));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    $this->withinTenant($this->school, fn () => $this->payments->refund(
        payment: $payment,
        amount: Money::of(40_000, 'HTG'),
        reason: 'Overcharged for the canteen',
    ));

    $invoice->refresh();

    expect($invoice->paid_minor)->toBe(60_000)
        ->and($invoice->balance_minor)->toBe(40_000)
        ->and($invoice->status)->not->toBe(InvoiceStatus::Paid);
});

it('refuses a refund larger than the payment', function (): void {
    $invoice = makeIssuedInvoice($this, 20_000);

    $payment = $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(20_000, 'HTG'),
        method: PaymentMethod::Cash,
    ));

    $this->withinTenant($this->school, fn () => $this->payments->refund(
        payment: $payment,
        amount: Money::of(25_000, 'HTG'),
        reason: 'Too much',
    ));
})->throws(DomainException::class);

it('will not refund more than what remains refundable across several refunds', function (): void {
    $invoice = makeIssuedInvoice($this, 30_000);

    $payment = $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        invoice: $invoice,
        amount: Money::of(30_000, 'HTG'),
        method: PaymentMethod::Cash,
    ));

    $this->withinTenant($this->school, fn () => $this->payments->refund($payment, Money::of(20_000, 'HTG'), 'first'));

    expect(fn () => $this->withinTenant(
        $this->school,
        fn () => $this->payments->refund($payment->fresh(), Money::of(15_000, 'HTG'), 'second'),
    ))->toThrow(DomainException::class);
});

// --------------------------------------------------------------- invariants

it('keeps the database-level balance invariant on every path', function (): void {
    $invoice = makeIssuedInvoice($this, 90_000);

    $this->withinTenant($this->school, function () use ($invoice): void {
        $this->payments->recordManualPayment($invoice, Money::of(30_000, 'HTG'), PaymentMethod::Cash);
        $this->payments->recordManualPayment($invoice->fresh(), Money::of(20_000, 'HTG'), PaymentMethod::Cheque);
    });

    $drift = Invoice::query()
        ->withoutTenantScope()
        ->whereRaw('balance_minor <> total_minor - paid_minor')
        ->count();

    expect($drift)->toBe(0)
        ->and($invoice->fresh()->balance_minor)->toBe(40_000);
});

it('cannot cancel an invoice that has taken money', function (): void {
    $invoice = makeIssuedInvoice($this, 10_000);

    $this->withinTenant($this->school, fn () => $this->payments->recordManualPayment(
        $invoice, Money::of(5_000, 'HTG'), PaymentMethod::Cash,
    ));

    $this->withinTenant($this->school, fn () => $this->invoices->cancel($invoice->fresh(), 'changed mind'));
})->throws(DomainException::class);
