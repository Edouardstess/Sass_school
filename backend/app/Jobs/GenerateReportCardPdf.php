<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Academic\Models\ReportCard;
use App\Domain\Document\Models\Document;
use App\Domain\Document\Services\DocumentStorage;
use App\Infrastructure\Pdf\PdfRenderer;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders a report card to PDF.
 *
 * Queued rather than inline because publishing a year group means several
 * hundred renders; doing that in a request would time out, and doing it
 * synchronously in the publish call would make the API unusable.
 */
class GenerateReportCardPdf implements ShouldQueue
{
    use Queueable, RunsInTenant;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $reportCardId,
        public readonly string $schoolId,
    ) {
        $this->onQueue('documents');
    }

    public function handle(PdfRenderer $renderer, DocumentStorage $storage): void
    {
        $this->inTenant($this->schoolId, function () use ($renderer, $storage): void {
            $card = ReportCard::query()
                ->with([
                    'student', 'schoolClass.level', 'gradePeriod.academicYear',
                    'lines' => fn ($q) => $q->orderBy('subject_name'),
                    'school',
                ])
                ->find($this->reportCardId);

            if ($card === null) {
                return;
            }

            $pdf = $renderer->render('pdf.report-card', [
                'card' => $card,
                'student' => $card->student,
                'class' => $card->schoolClass,
                'period' => $card->gradePeriod,
                'year' => $card->gradePeriod?->academicYear,
                'lines' => $card->lines,
                'school' => $card->school,
                'logo' => $renderer->inlineImage($card->school?->logo_path),
            ]);

            $document = $storage->storeGenerated(
                contents: $pdf,
                filename: sprintf('Bulletin-%s-%s.pdf', $card->student?->matricule, $card->gradePeriod?->sequence),
                collection: Document::COLLECTION_REPORT_CARD,
                attachTo: $card,
                schoolId: $this->schoolId,
            );

            // Replacing an earlier render is correct: a recomputed card should
            // not leave the previous PDF as the one parents download.
            $card->forceFill(['document_id' => $document->id])->save();
        });
    }
}
