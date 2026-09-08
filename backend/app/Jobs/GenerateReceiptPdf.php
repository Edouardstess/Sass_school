<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Document\Models\Document;
use App\Domain\Document\Services\DocumentStorage;
use App\Domain\Finance\Models\Receipt;
use App\Infrastructure\Pdf\PdfRenderer;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders a receipt to PDF after a payment is confirmed.
 *
 * Idempotent by construction: a receipt that already has a `document_id` is
 * left alone, so a retried job does not produce a second file. Rendering is
 * off the request path so confirming a payment never waits on a PDF engine.
 */
class GenerateReceiptPdf implements ShouldQueue
{
    use Queueable, RunsInTenant;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $receiptId,
        public readonly string $schoolId,
    ) {
        $this->onQueue('documents');
    }

    public function handle(PdfRenderer $renderer, DocumentStorage $storage): void
    {
        $this->inTenant($this->schoolId, function () use ($renderer, $storage): void {
            $receipt = Receipt::query()
                ->with(['payment', 'invoice.items', 'student', 'school'])
                ->find($this->receiptId);

            if ($receipt === null || $receipt->document_id !== null) {
                return;   // gone, or already rendered
            }

            $pdf = $renderer->render('pdf.receipt', [
                'receipt' => $receipt,
                'payment' => $receipt->payment,
                'invoice' => $receipt->invoice,
                'student' => $receipt->student,
                'school' => $receipt->school,
                'logo' => $renderer->inlineImage($receipt->school?->logo_path),
            ]);

            $document = $storage->storeGenerated(
                contents: $pdf,
                filename: "Recu-{$receipt->number}.pdf",
                collection: Document::COLLECTION_RECEIPT,
                attachTo: $receipt,
                schoolId: $this->schoolId,
            );

            $receipt->forceFill(['document_id' => $document->id])->save();
        });
    }
}
