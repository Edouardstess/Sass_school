<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class UserPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['users.view', 'platform.users.manage']);
    }

    public function view(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;    // everyone can see their own profile
        }

        if ($user->hasPermission('platform.users.manage')) {
            return true;
        }

        return $this->allows($user, $target, 'users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['users.create', 'platform.users.manage']);
    }

    public function update(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;
        }

        if ($user->hasPermission('platform.users.manage')) {
            return true;
        }

        return $this->allows($user, $target, 'users.update');
    }

    /**
     * Deleting yourself is refused: an administrator who removes their own
     * account can lock a school out of its own tenant.
     */
    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        if ($user->hasPermission('platform.users.manage')) {
            return true;
        }

        return $this->allows($user, $target, 'users.delete');
    }

    /**
     * Changing your own roles is refused outright — that is the textbook
     * privilege-escalation path, and there is no legitimate use for it.
     */
    public function manageRoles(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        if ($user->hasPermission('platform.users.manage')) {
            return true;
        }

        return $this->allows($user, $target, 'users.roles.manage');
    }
}
