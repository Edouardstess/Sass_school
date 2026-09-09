<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Document\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guardian's explanation for an absence, awaiting administrative approval.
 * Approving one flips `is_justified` on the underlying record.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $updated_at
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

    /** @return BelongsTo<AttendanceRecord, $this> */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
