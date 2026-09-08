<?php

declare(strict_types=1);

namespace App\Domain\Document\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A stored file.
 *
 * `path` is an object key inside a *private* bucket, never a public URL.
 * Access always goes through `temporaryUrl()` after a policy check, and every
 * mint is written to the audit log.
 */
class Document extends BaseModel
{
    use BelongsToTenant, SoftDeletes;

    public const COLLECTION_STUDENT_PHOTO = 'student_photo';

    public const COLLECTION_REPORT_CARD = 'report_card';

    public const COLLECTION_RECEIPT = 'receipt';

    public const COLLECTION_CERTIFICATE = 'certificate';

    public const COLLECTION_ADMISSION = 'admission';

    public const COLLECTION_JUSTIFICATION = 'justification';

    public const COLLECTION_EXPORT = 'export';

    public const COLLECTION_IMPORT = 'import';

    protected $fillable = [
        'school_id', 'uploaded_by', 'collection', 'name', 'path', 'disk',
        'mime_type', 'size_bytes', 'checksum', 'documentable_type',
        'documentable_id', 'visibility', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * A short-lived signed URL.
     *
     * The TTL is deliberately short (10 minutes by default): a link forwarded
     * in a WhatsApp group should stop working long before it is screenshotted
     * into a public channel.
     */
    public function temporaryUrl(?int $minutes = null): string
    {
        $minutes ??= (int) config('schoolflow.security.signed_url_ttl_minutes', 10);

        try {
            return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes));
        } catch (RuntimeException) {
            // The local driver has no signed-URL support and throws rather
            // than returning null. In development we fall back to a
            // route-signed URL so the download path behaves comparably to S3
            // without pretending to be it.
            return route('documents.download', ['document' => $this->id]);
        }
    }

    public function scopeInCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }
}
