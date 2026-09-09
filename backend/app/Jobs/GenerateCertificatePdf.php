<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Document\Models\Certificate;
use App\Domain\Document\Models\Document;
use App\Domain\Document\Services\DocumentStorage;
use App\Infrastructure\Pdf\PdfRenderer;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Renders an issued certificate to PDF, carrying its verification code. */
class GenerateCertificatePdf implements ShouldQueue
{
    use Queueable, RunsInTenant;

    public int $tries = 3;

    public function __construct(
        public readonly string $certificateId,
        public readonly string $schoolId,
    ) {
        $this->onQueue('documents');
    }

    public function handle(PdfRenderer $renderer, DocumentStorage $storage): void
    {
        $this->inTenant($this->schoolId, function () use ($renderer, $storage): void {
            $certificate = Certificate::query()->with(['student', 'school'])->find($this->certificateId);

            if ($certificate === null || $certificate->document_id !== null) {
                return;
            }

            $payload = $certificate->payload ?? [];

            $pdf = $renderer->render('pdf.certificate', [
                'certificate' => $certificate,
                'school' => $certificate->school,
                'logo' => $renderer->inlineImage($certificate->school?->logo_path),
                'title' => $this->titleFor($certificate->type),
                'body' => $this->bodyFor($certificate->type, $payload),
                'verificationUrl' => rtrim((string) config('app.frontend_url'), '/').'/verify',
            ]);

            $document = $storage->storeGenerated(
                contents: $pdf,
                filename: "Certificat-{$certificate->number}.pdf",
                collection: Document::COLLECTION_CERTIFICATE,
                attachTo: $certificate,
                schoolId: $this->schoolId,
            );

            $certificate->forceFill(['document_id' => $document->id])->save();
        });
    }

    private function titleFor(string $type): string
    {
        return match ($type) {
            Certificate::TYPE_ENROLLMENT => __('certificates.enrollment_title'),
            Certificate::TYPE_ATTENDANCE => __('certificates.attendance_title'),
            Certificate::TYPE_COMPLETION => __('certificates.completion_title'),
            Certificate::TYPE_TRANSCRIPT => __('certificates.transcript_title'),
            default => __('pdf.certificate'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function bodyFor(string $type, array $payload): string
    {
        $key = match ($type) {
            Certificate::TYPE_ENROLLMENT => 'certificates.enrollment_body',
            Certificate::TYPE_ATTENDANCE => 'certificates.attendance_body',
            Certificate::TYPE_COMPLETION => 'certificates.completion_body',
            default => 'certificates.generic_body',
        };

        // e() on every interpolated value: the payload is frozen student data,
        // and the result is injected into the template unescaped.
        return __($key, [
            'student' => e((string) ($payload['student_name'] ?? '')),
            'matricule' => e((string) ($payload['matricule'] ?? '')),
            'class' => e((string) ($payload['class_name'] ?? '')),
            'level' => e((string) ($payload['level_name'] ?? '')),
            'year' => e((string) ($payload['academic_year'] ?? '')),
            'school' => e((string) ($payload['school_name'] ?? '')),
        ]);
    }
}
