<?php

declare(strict_types=1);

namespace App\Domain\Document\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Document\Models\Document;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only way files enter or leave SchoolFlow.
 *
 * Three properties this centralisation buys:
 *
 *  - **Path safety.** Object keys are built from the tenant id, the collection
 *    and a fresh UUID. The user's filename never reaches the key, so `../`
 *    and null bytes have nothing to traverse.
 *  - **Type safety.** The MIME type is taken from the file's own content, not
 *    from the client-supplied Content-Type, and checked against an allow-list.
 *    A PHP file renamed to `.jpg` is rejected on its real type.
 *  - **Auditability.** Uploads and download-link mints both write an audit
 *    entry, which is what makes DOCUMENT_DOWNLOAD a real record rather than an
 *    aspiration.
 */
final class DocumentStorage
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function store(
        UploadedFile $file,
        string $collection,
        ?Model $attachTo = null,
        ?string $uploadedBy = null,
        string $visibility = 'private',
    ): Document {
        $this->assertAcceptable($file);

        $schoolId = $this->tenant->idOrFail();
        $disk = (string) config('schoolflow.storage.disk', 's3');

        // The stored key is entirely of our own construction.
        $extension = $this->safeExtension($file);
        $path = sprintf('schools/%s/%s/%s%s', $schoolId, $collection, Str::uuid(), $extension);

        Storage::disk($disk)->put($path, $file->get(), ['visibility' => 'private']);

        $document = new Document;
        $document->forceFill([
            'school_id' => $schoolId,
            'uploaded_by' => $uploadedBy,
            'collection' => $collection,
            // The original name is kept for display only, sanitised so it
            // cannot carry markup into a UI or a Content-Disposition header.
            'name' => $this->sanitiseName($file->getClientOriginalName()),
            'path' => $path,
            'disk' => $disk,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'checksum' => hash('sha256', $file->get()),
            'documentable_type' => $attachTo?->getMorphClass(),
            'documentable_id' => $attachTo?->getKey(),
            'visibility' => $visibility,
        ])->save();

        $this->audit->log(AuditAction::DocumentUpload, $document, [
            'description' => "Uploaded {$document->name} to {$collection}",
        ]);

        return $document;
    }

    /** Store bytes we generated ourselves (a rendered PDF, an export file). */
    public function storeGenerated(
        string $contents,
        string $filename,
        string $collection,
        string $mimeType = 'application/pdf',
        ?Model $attachTo = null,
        ?string $schoolId = null,
    ): Document {
        $schoolId ??= $this->tenant->idOrFail();
        $disk = (string) config('schoolflow.storage.disk', 's3');
        $path = sprintf('schools/%s/%s/%s.%s', $schoolId, $collection, Str::uuid(), $this->extensionForMime($mimeType));

        Storage::disk($disk)->put($path, $contents, ['visibility' => 'private']);

        $document = new Document;
        $document->forceFill([
            'school_id' => $schoolId,
            'collection' => $collection,
            'name' => $this->sanitiseName($filename),
            'path' => $path,
            'disk' => $disk,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'documentable_type' => $attachTo?->getMorphClass(),
            'documentable_id' => $attachTo?->getKey(),
            'visibility' => 'private',
        ])->save();

        return $document;
    }

    /**
     * Mint a short-lived download URL.
     *
     * Authorisation is the caller's job (DocumentPolicy); this records that it
     * happened, so a leaked link can be traced back to who asked for it.
     */
    public function temporaryUrlFor(Document $document, ?string $userId = null): string
    {
        $this->audit->log(AuditAction::DocumentDownload, $document, [
            'description' => "Download link issued for {$document->name}",
            'user_id' => $userId,
        ]);

        return $document->temporaryUrl();
    }

    public function contents(Document $document): string
    {
        $contents = Storage::disk($document->disk)->get($document->path);

        if ($contents === null) {
            throw new DomainException(__('documents.missing_file'));
        }

        return $contents;
    }

    public function delete(Document $document): void
    {
        Storage::disk($document->disk)->delete($document->path);
        $document->delete();

        $this->audit->deleted($document, "Deleted document {$document->name}");
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new DomainException(__('documents.upload_failed'));
        }

        $maxBytes = (int) config('schoolflow.storage.max_upload_bytes');

        if ($file->getSize() > $maxBytes) {
            throw new DomainException(__('documents.too_large', [
                'max' => round($maxBytes / 1024 / 1024).' MB',
            ]));
        }

        // getMimeType() sniffs the content; getClientMimeType() would trust
        // the browser, which is trusting the attacker.
        $mime = $file->getMimeType();
        $allowed = config('schoolflow.storage.allowed_mime_types', []);

        if ($mime === null || ! in_array($mime, $allowed, true)) {
            throw new DomainException(__('documents.unsupported_type', ['type' => $mime ?? 'unknown']));
        }
    }

    /** Derive the extension from the sniffed MIME type, never from the name. */
    private function safeExtension(UploadedFile $file): string
    {
        $guessed = $file->guessExtension();

        return $guessed !== null && preg_match('/^[a-z0-9]{1,8}$/i', $guessed) === 1
            ? '.'.strtolower($guessed)
            : '';
    }

    private function extensionForMime(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'text/csv' => 'csv',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };
    }

    private function sanitiseName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $name) ?? 'document';

        return Str::limit(trim($name) ?: 'document', 180, '');
    }
}
