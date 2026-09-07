<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Teacher\Models\Teacher;
use App\Policies\Concerns\ChecksTenant;

class TeacherPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('teachers.view');
    }

    public function view(User $user, Teacher $teacher): bool
    {
        // A teacher can always read their own profile, even without the
        // directory-wide permission.
        if ($teacher->user_id !== null && $teacher->user_id === $user->id) {
            return true;
        }

        return $this->allows($user, $teacher, 'teachers.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('teachers.create');
    }

    public function update(User $user, Teacher $teacher): bool
    {
        return $this->allows($user, $teacher, 'teachers.update');
    }

    public function delete(User $user, Teacher $teacher): bool
    {
        return $this->allows($user, $teacher, 'teachers.delete');
    }
}
