<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of a mark change: who, when, from what, to what, why. */
class GradeRevision extends BaseModel
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'school_id', 'grade_id', 'changed_by', 'old_score', 'new_score', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_score' => 'decimal:2',
            'new_score' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
