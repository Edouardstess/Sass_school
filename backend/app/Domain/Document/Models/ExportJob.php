<?php

declare(strict_types=1);

namespace App\Domain\Document\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A queued export, downloadable once the worker has produced the file. */
class ExportJob extends BaseModel
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'school_id', 'user_id', 'document_id', 'type', 'format',
        'filters', 'status', 'error', 'completed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
