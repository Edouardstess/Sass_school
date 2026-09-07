<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry in the append-only audit trail.
 *
 * Deliberately not tenant-scoped by the global scope, because the platform
 * audit view spans tenants; the tenant-facing controller filters explicitly.
 * The table itself rejects UPDATE and DELETE via PostgreSQL rules, so this
 * model has no update path by construction.
 */
class AuditLog extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'school_id', 'user_id', 'action', 'resource_type', 'resource_id',
        'description', 'old_values', 'new_values', 'metadata',
        'ip_address', 'user_agent', 'request_id', 'acting_as_platform_admin', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'acting_as_platform_admin' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function scopeForTenant(Builder $query, string $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }
}
