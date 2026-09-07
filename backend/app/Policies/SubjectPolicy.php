<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Academic\Models\Subject;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class SubjectPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('subjects.view');
    }

    public function view(User $user, Subject $subject): bool
    {
        return $this->allows($user, $subject, 'subjects.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('subjects.create');
    }

    public function update(User $user, Subject $subject): bool
    {
        return $this->allows($user, $subject, 'subjects.update');
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $this->allows($user, $subject, 'subjects.delete');
    }
}
