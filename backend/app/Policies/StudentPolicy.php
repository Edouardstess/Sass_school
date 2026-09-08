<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Student\Models\Student;
use App\Policies\Concerns\ChecksTenant;

/**
 * Who may see and change a student record.
 *
 * The interesting case is `students.view_own`: parents and students hold it
 * instead of `students.view`, and it authorises access to *their* records
 * only. That relationship check is done here, in the domain, rather than being
 * left to each controller to remember.
 */
class StudentPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['students.view', 'students.view_own']);
    }

    public function view(User $user, Student $student): bool
    {
        if (! $this->sameTenant($user, $student)) {
            return false;
        }

        if ($user->hasPermission('students.view')) {
            return true;
        }

        return $user->hasPermission('students.view_own') && $this->isRelated($user, $student);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('students.create');
    }

    public function update(User $user, Student $student): bool
    {
        return $this->allows($user, $student, 'students.update');
    }

    public function delete(User $user, Student $student): bool
    {
        return $this->allows($user, $student, 'students.delete');
    }

    public function transfer(User $user, Student $student): bool
    {
        return $this->allows($user, $student, 'students.transfer');
    }

    public function import(User $user): bool
    {
        return $user->hasPermission('students.import');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('students.export');
    }

    /**
     * True when the user *is* the student, or is one of their guardians.
     *
     * Both checks run against the tenant-scoped relations, so a guardian at
     * another school with the same name cannot match.
     */
    public function isRelated(User $user, Student $student): bool
    {
        if ($student->user_id !== null && $student->user_id === $user->id) {
            return true;
        }

        $guardian = $user->loadMissing('guardian')->guardian;

        if ($guardian === null) {
            return false;
        }

        return $student->guardians()->whereKey($guardian->id)->exists();
    }
}
