<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenancy re-check for policies.
 *
 * The global scope already prevents a cross-tenant record from being loaded,
 * so in practice this never fires. It exists precisely because "in practice"
 * is not a security guarantee: if a record is ever fetched through a raw
 * query, a relation loaded without the scope, or a future refactor, this
 * catches it before the user is authorised.
 */
trait ChecksTenant
{
    protected function sameTenant(User $user, Model $model): bool
    {
        $recordSchoolId = $model->getAttribute('school_id');

        if ($recordSchoolId === null) {
            return true;   // platform-level record, tenancy does not apply
        }

        // A platform admin only ever sees tenant data through an explicit,
        // audited impersonation, and then only for the school they named.
        if ($user->isPlatformAdmin()) {
            return app(TenantContext::class)->id() === $recordSchoolId;
        }

        return $user->school_id === $recordSchoolId;
    }

    /** Permission AND tenancy — the two must always be checked together. */
    protected function allows(User $user, Model $model, string $permission): bool
    {
        return $this->sameTenant($user, $model) && $user->hasPermission($permission);
    }
}
