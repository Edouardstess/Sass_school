<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;

class SchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('platform.schools.view');
    }

    /** A tenant user may only ever view their own school. */
    public function view(User $user, School $school): bool
    {
        if ($user->hasPermission('platform.schools.view')) {
            return true;
        }

        return $user->school_id === $school->id && $user->hasPermission('school.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('platform.schools.manage');
    }

    public function update(User $user, School $school): bool
    {
        if ($user->hasPermission('platform.schools.manage')) {
            return true;
        }

        return $user->school_id === $school->id && $user->hasPermission('school.update');
    }

    /** Suspension is a platform action; a school cannot suspend itself. */
    public function suspend(User $user, School $school): bool
    {
        return $user->hasPermission('platform.schools.suspend');
    }

    public function manageSettings(User $user, School $school): bool
    {
        return $user->school_id === $school->id && $user->hasPermission('school.settings.manage');
    }
}
