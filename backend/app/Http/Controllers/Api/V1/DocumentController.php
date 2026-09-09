<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Document\Models\Document;
use App\Domain\Document\Services\DocumentStorage;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        $documents = Document::query()
            ->when($request->filled('collection'), fn (Builder $q) => $q->inCollection((string) $request->query('collection')))
            ->when($request->filled('documentable_type'), fn (Builder $q) => $q
                ->where('documentable_type', $request->query('documentable_type'))
                ->where('documentable_id', $request->query('documentable_id')))
            ->latest()
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($documents, null);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('upload', Document::class);

        $data = $request->validate([
            'file' => [
                'required', 'file',
                'max:'.((int) config('schoolflow.storage.max_upload_bytes') / 1024),
                // The MIME check in DocumentStorage sniffs the content; this
                // is the cheap first filter, not the security boundary.
                'mimes:jpg,jpeg,png,webp,pdf,docx,xlsx,csv,txt',
            ],
            'collection' => ['required', 'string', 'max:60'],
            'documentable_type' => ['nullable', 'string', 'max:120'],
            'documentable_id' => ['nullable', 'uuid'],
        ]);

        $attachTo = null;

        if (! empty($data['documentable_type']) && ! empty($data['documentable_id'])) {
            $modelClass = Relation::getMorphedModel($data['documentable_type']);

            if ($modelClass !== null) {
                // Resolved through the tenant-scoped query, so a document
                // cannot be attached to another school's record.
                $attachTo = $modelClass::query()->find($data['documentable_id']);
            }
        }

        $document = $this->storage->store(
            file: $request->file('file'),
            collection: $data['collection'],
            attachTo: $attachTo,
            uploadedBy: $this->user($request)->id,
        );

        return ApiResponse::created($this->present($document), __('responses.created'));
    }

    /** A short-lived signed URL; the mint itself is audited. */
    public function download(Request $request, Document $document): JsonResponse
    {
        $this->authorize('download', $document);

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrlFor($document, $this->user($request)->id),
            'name' => $document->name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'expires_in_minutes' => (int) config('schoolflow.security.signed_url_ttl_minutes', 10),
        ]);
    }

    /**
     * Stream the bytes.
     *
     * Used by the local filesystem driver, which cannot mint signed URLs.
     * The policy check happens here, so this is not a bypass of the S3 path.
     */
    public function stream(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('download', $document);

        return Storage::disk($document->disk)->download($document->path, $document->name, [
            'Content-Type' => $document->mime_type,
            // Never inline: an uploaded SVG or HTML rendered in the browser
            // would execute on our origin.
            'Content-Disposition' => 'attachment; filename="'.addslashes($document->name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $this->storage->delete($document);

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function present(Document $document): array
    {
        return [
            'id' => $document->id,
            'name' => $document->name,
            'collection' => $document->collection,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
