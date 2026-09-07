<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Document\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guardian's explanation for an absence, awaiting administrative approval.
 * Approving one flips `is_justified` on the underlying record.
 */
class AttendanceJustification extends BaseModel
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'school_id', 'attendance_record_id', 'submitted_by', 'reason',
        'document_id', 'status',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime'];
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
