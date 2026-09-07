<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class SchoolClassPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function view(User $user, SchoolClass $class): bool
    {
        return $this->allows($user, $class, 'classes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('classes.create');
    }

    public function update(User $user, SchoolClass $class): bool
    {
        return $this->allows($user, $class, 'classes.update');
    }

    public function delete(User $user, SchoolClass $class): bool
    {
        return $this->allows($user, $class, 'classes.delete');
    }

    public function assignTeacher(User $user, SchoolClass $class): bool
    {
        return $this->allows($user, $class, 'classes.assign_teacher');
    }
}
