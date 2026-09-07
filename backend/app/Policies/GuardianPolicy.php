<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Student\Models\Guardian;
use App\Policies\Concerns\ChecksTenant;

class GuardianPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('guardians.view');
    }

    public function view(User $user, Guardian $guardian): bool
    {
        if ($guardian->user_id !== null && $guardian->user_id === $user->id) {
            return true;
        }

        return $this->allows($user, $guardian, 'guardians.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('guardians.create');
    }

    public function update(User $user, Guardian $guardian): bool
    {
        if ($guardian->user_id !== null && $guardian->user_id === $user->id) {
            return true;
        }

        return $this->allows($user, $guardian, 'guardians.update');
    }

    public function delete(User $user, Guardian $guardian): bool
    {
        return $this->allows($user, $guardian, 'guardians.delete');
    }
}
