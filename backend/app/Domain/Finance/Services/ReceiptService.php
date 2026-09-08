<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\Receipt;
use App\Domain\Shared\Services\NumberGenerator;
use App\Jobs\GenerateReceiptPdf;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Issues receipts.
 *
 * `receipts.payment_id` is uniquely indexed, and this method is written around
 * that: it checks first, and treats a unique violation as "someone else got
 * there" rather than an error. That is what makes it safe to call from a
 * retried queue job or a duplicated webhook.
 *
 * PDF rendering is deliberately *not* done here — it is dispatched to the
 * `documents` queue, so confirming a payment never waits on a PDF engine.
 */
final class ReceiptService
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    public function issueFor(Payment $payment): Receipt
    {
        $existing = Receipt::query()->where('payment_id', $payment->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $receipt = new Receipt;
            $receipt->forceFill([
                'school_id' => $payment->school_id,
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'student_id' => $payment->student_id,
                'number' => $this->numbers->next('receipt', null, $payment->school_id),
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'issued_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Concurrent issue; the other transaction's receipt is the one.
            return Receipt::query()->where('payment_id', $payment->id)->firstOrFail();
        }

        // Rendering happens out of band on the documents queue.
        GenerateReceiptPdf::dispatch($receipt->id, $receipt->school_id)
            ->onQueue('documents');

        return $receipt;
    }
}
