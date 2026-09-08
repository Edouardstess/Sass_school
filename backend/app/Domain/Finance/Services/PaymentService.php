<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTransaction;
use App\Domain\Finance\Models\Receipt;
use App\Domain\Finance\Models\Refund;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Enums\PaymentMethod;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Services\NumberGenerator;
use App\Domain\Shared\ValueObjects\Money;
use App\Infrastructure\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * Applies money to invoices.
 *
 * The rule that shapes this whole class: **a payment only ever becomes
 * `succeeded` server-side.** Manual methods settle because a staff member with
 * `payments.create` vouched for them; gateway methods settle only when a
 * signature-verified webhook says so. A browser landing on a success URL
 * changes nothing here.
 *
 * Every settlement runs inside a transaction that holds a row lock on the
 * invoice, so two concurrent payments cannot both read the same balance and
 * both decide they are the final one.
 */
final class PaymentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly PaymentGatewayManager $gateways,
        private readonly ReceiptService $receipts,
    ) {}

    /**
     * Record a payment collected outside the platform (cash, bank transfer,
     * cheque). Settles immediately.
     */
    public function recordManualPayment(
        Invoice $invoice,
        Money $amount,
        PaymentMethod $method,
        ?string $recordedBy = null,
        ?string $payerName = null,
        ?string $externalReference = null,
        ?string $notes = null,
    ): Payment {
        if (! $method->isManual()) {
            throw new DomainException(__('finance.method_requires_gateway', ['method' => $method->value]));
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $recordedBy, $payerName, $externalReference, $notes): Payment {
            $locked = $this->lockInvoice($invoice);

            $this->assertPayable($locked, $amount);

            $payment = new Payment;
            $payment->forceFill([
                'school_id' => $locked->school_id,
                'invoice_id' => $locked->id,
                'student_id' => $locked->student_id,
                'recorded_by' => $recordedBy,
                'reference' => $this->numbers->next('payment', null, $locked->school_id),
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'method' => $method->value,
                'status' => PaymentStatus::Succeeded->value,
                'paid_at' => now(),
                'payer_name' => $payerName,
                'external_reference' => $externalReference,
                'notes' => $notes,
            ])->save();

            $this->invoices->recalculate($locked);
            $receipt = $this->receipts->issueFor($payment);

            $this->audit->log(AuditAction::Payment, $payment, [
                'description' => sprintf(
                    'Payment %s of %s recorded (%s) against invoice %s',
                    $payment->reference, (string) $amount, $method->value, $locked->number,
                ),
                'new_values' => [
                    'amount_minor' => $amount->minorUnits,
                    'currency' => $amount->currency,
                    'method' => $method->value,
                    'receipt_number' => $receipt->number,
                ],
            ]);

            return $payment->fresh(['receipt']);
        });
    }

    /**
     * Start an online payment.
     *
     * Creates a *pending* payment plus a provider transaction and hands back
     * the checkout URL. Nothing is applied to the invoice at this point —
     * that waits for the webhook.
     */
    public function initiateGatewayPayment(
        Invoice $invoice,
        Money $amount,
        PaymentMethod $method,
        array $context = [],
    ): PaymentTransaction {
        $gateway = $this->gateways->get($method->gatewayKey());

        if (! $gateway->isAvailable()) {
            throw new DomainException(__('finance.gateway_unavailable', ['gateway' => $gateway->displayName()]));
        }

        if (! in_array($amount->currency, $gateway->supportedCurrencies(), true)) {
            throw new DomainException(__('finance.gateway_currency_unsupported', [
                'gateway' => $gateway->displayName(),
                'currency' => $amount->currency,
            ]));
        }

        $this->assertPayable($invoice, $amount);

        $charge = $gateway->initiate($invoice, $amount, $context);

        return DB::transaction(function () use ($invoice, $amount, $method, $charge): PaymentTransaction {
            $payment = new Payment;
            $payment->forceFill([
                'school_id' => $invoice->school_id,
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'reference' => $this->numbers->next('payment', null, $invoice->school_id),
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'method' => $method->value,
                // Pending on purpose: it contributes nothing to the balance.
                'status' => PaymentStatus::Pending->value,
                'external_reference' => $charge->reference,
            ])->save();

            $transaction = new PaymentTransaction;
            $transaction->forceFill([
                'school_id' => $invoice->school_id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'provider' => $charge->provider,
                'provider_reference' => $charge->reference,
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'status' => $charge->status,
                'checkout_url' => $charge->checkoutUrl,
                'expires_at' => $charge->expiresAt,
                'provider_payload' => $charge->rawResponse,
            ])->save();

            return $transaction;
        });
    }

    /**
     * Settle a payment because a verified webhook (or an authoritative status
     * re-query) says the provider took the money.
     *
     * Idempotent: a payment already in `succeeded` returns unchanged, so a
     * duplicated webhook cannot credit an invoice twice.
     */
    public function confirmGatewayPayment(
        PaymentTransaction $transaction,
        ?string $providerReference = null,
    ): Payment {
        return DB::transaction(function () use ($transaction, $providerReference): Payment {
            $payment = Payment::query()
                ->whereKey($transaction->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status === PaymentStatus::Succeeded) {
                return $payment;   // already applied; nothing to do
            }

            if (! $payment->status->canTransitionTo(PaymentStatus::Succeeded)) {
                throw new DomainException(__('finance.payment_not_confirmable', [
                    'status' => $payment->status->value,
                ]));
            }

            $invoice = $this->lockInvoice($payment->invoice);

            $payment->forceFill([
                'status' => PaymentStatus::Succeeded->value,
                'paid_at' => now(),
                'external_reference' => $providerReference ?? $payment->external_reference,
            ])->save();

            $transaction->forceFill(['status' => PaymentTransaction::STATUS_SUCCEEDED])->save();

            $this->invoices->recalculate($invoice);
            $receipt = $this->receipts->issueFor($payment);

            $this->audit->log(AuditAction::Payment, $payment, [
                'description' => sprintf(
                    'Gateway payment %s confirmed via %s for invoice %s',
                    $payment->reference, $transaction->provider, $invoice->number,
                ),
                'new_values' => ['receipt_number' => $receipt->number],
            ]);

            return $payment;
        });
    }

    public function failGatewayPayment(PaymentTransaction $transaction, ?string $reason = null): void
    {
        DB::transaction(function () use ($transaction, $reason): void {
            $transaction->forceFill([
                'status' => PaymentTransaction::STATUS_FAILED,
                'failure_reason' => $reason,
            ])->save();

            $payment = Payment::query()->whereKey($transaction->payment_id)->lockForUpdate()->first();

            // A payment that already succeeded is never downgraded by a later
            // failure notification — that would be a way to erase a receipt.
            if ($payment !== null && $payment->status->canTransitionTo(PaymentStatus::Failed)) {
                $payment->forceFill([
                    'status' => PaymentStatus::Failed->value,
                    'notes' => $reason,
                ])->save();
            }
        });
    }

    /**
     * Refund all or part of a settled payment.
     *
     * The refunded amount is deducted in `InvoiceService::recalculate`, so the
     * invoice balance reopens correctly rather than being patched by hand.
     */
    public function refund(Payment $payment, Money $amount, string $reason, ?string $approvedBy = null): Refund
    {
        if (! $payment->status->isSuccessful()) {
            throw new DomainException(__('finance.refund_requires_successful_payment'));
        }

        $refundable = $payment->refundableAmount();

        if ($amount->greaterThan($refundable)) {
            throw new DomainException(__('finance.refund_exceeds_payment', [
                'refundable' => (string) $refundable,
            ]));
        }

        if (! $amount->isPositive()) {
            throw new DomainException(__('finance.refund_must_be_positive'));
        }

        return DB::transaction(function () use ($payment, $amount, $reason, $approvedBy): Refund {
            $invoice = $this->lockInvoice($payment->invoice);

            $refund = new Refund;
            $refund->forceFill([
                'school_id' => $payment->school_id,
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'approved_by' => $approvedBy,
                'reference' => $this->numbers->next('refund', null, $payment->school_id),
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'reason' => $reason,
                'status' => Refund::STATUS_APPROVED,
            ])->save();

            // A payment refunded in full leaves the successful set entirely.
            if ($payment->refundedAmount()->add($amount)->greaterThanOrEqual($payment->amount())) {
                $payment->forceFill(['status' => PaymentStatus::Refunded->value])->save();
            }

            $this->invoices->recalculate($invoice);

            $this->audit->log(AuditAction::Refund, $refund, [
                'description' => sprintf(
                    'Refund %s of %s issued against payment %s',
                    $refund->reference, (string) $amount, $payment->reference,
                ),
                'new_values' => ['amount_minor' => $amount->minorUnits, 'reason' => $reason],
            ]);

            return $refund;
        });
    }

    /**
     * Re-read the invoice under a row lock.
     *
     * Everything that changes a balance goes through here, so concurrent
     * payments serialise on the invoice row instead of interleaving.
     */
    private function lockInvoice(Invoice $invoice): Invoice
    {
        return Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    private function assertPayable(Invoice $invoice, Money $amount): void
    {
        if (! $invoice->status->canAcceptPayment()) {
            throw new DomainException(__('finance.invoice_not_payable', [
                'number' => $invoice->number,
                'status' => $invoice->status->value,
            ]));
        }

        if (! $amount->isPositive()) {
            throw new DomainException(__('finance.payment_must_be_positive'));
        }

        if ($amount->currency !== $invoice->currency) {
            throw new DomainException(__('finance.payment_currency_mismatch', [
                'invoice' => $invoice->currency,
                'payment' => $amount->currency,
            ]));
        }

        // Overpayment is refused rather than turned into a credit note: a
        // credit balance is a product decision the school has not made yet,
        // and silently absorbing the excess would lose money.
        if ($amount->greaterThan($invoice->balance())) {
            throw new DomainException(__('finance.payment_exceeds_balance', [
                'balance' => (string) $invoice->balance(),
            ]));
        }
    }
}
