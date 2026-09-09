<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only trail of attendance corrections.
 * @property CarbonImmutable|null $created_at
 */
class AttendanceRevision extends BaseModel
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'school_id', 'attendance_record_id', 'changed_by',
        'old_status', 'new_status', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
