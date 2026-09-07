<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use App\Domain\Shared\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One limit or capability carried by a plan.
 *
 *   type=limit   → `limit_value` is the ceiling (null means unlimited)
 *   type=boolean → `enabled` says whether the capability is included
 */
class PlanFeature extends BaseModel
{
    public const TYPE_LIMIT = 'limit';

    public const TYPE_BOOLEAN = 'boolean';

    public const MAX_STUDENTS = 'max_students';

    public const MAX_USERS = 'max_users';

    public const MAX_STORAGE_MB = 'max_storage_mb';

    protected $fillable = ['plan_id', 'key', 'type', 'limit_value', 'enabled'];

    protected function casts(): array
    {
        return ['limit_value' => 'integer', 'enabled' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isUnlimited(): bool
    {
        return $this->type === self::TYPE_LIMIT && $this->limit_value === null;
    }
}
