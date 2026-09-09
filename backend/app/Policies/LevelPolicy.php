<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Academic\Models\Level;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

/**
 * Levels are structural configuration rather than a module of their own, so
 * they are governed by the same `classes.*` permissions as the classes that
 * sit inside them.
 */
class LevelPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function view(User $user, Level $level): bool
    {
        return $this->allows($user, $level, 'classes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('classes.create');
    }

    public function update(User $user, Level $level): bool
    {
        return $this->allows($user, $level, 'classes.update');
    }

    public function delete(User $user, Level $level): bool
    {
        return $this->allows($user, $level, 'classes.delete');
    }
}
