<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single authorization verb, e.g. `invoices.issue`.
 *
 * Permissions are global rows, not per-tenant: the vocabulary is part of the
 * product, and seeding it per school would multiply it by the tenant count for
 * no benefit.
 */
class Permission extends BaseModel
{
    protected $fillable = ['name', 'group', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permission')->withPivot('granted');
    }
}
