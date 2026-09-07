<?php

declare(strict_types=1);

namespace App\Domain\Document\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bulk import run.
 *
 * The two-step shape (validate, then confirm) is why this is a row and not a
 * single request: nothing is written until a human has seen the preview and
 * the error report.
 */
class ImportBatch extends BaseModel
{
    use BelongsToTenant;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'school_id', 'user_id', 'entity', 'original_filename', 'path', 'status',
        'total_rows', 'valid_rows', 'invalid_rows', 'imported_rows',
        'errors', 'preview', 'validated_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'preview' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'imported_rows' => 'integer',
            'validated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmable(): bool
    {
        return $this->status === self::STATUS_VALIDATED && $this->valid_rows > 0;
    }
}
