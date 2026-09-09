<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Policies\Concerns\ChecksTenant;

class AcademicYearPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('academic_years.view');
    }

    public function view(User $user, AcademicYear $year): bool
    {
        return $this->allows($user, $year, 'academic_years.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('academic_years.create');
    }

    public function update(User $user, AcademicYear $year): bool
    {
        // A closed year is a historical record; reopening it would silently
        // change report cards that families have already received.
        return $this->allows($user, $year, 'academic_years.update')
            && in_array($year->status, [AcademicYear::STATUS_DRAFT, AcademicYear::STATUS_ACTIVE], true);
    }

    public function activate(User $user, AcademicYear $year): bool
    {
        return $this->allows($user, $year, 'academic_years.activate')
            && $year->status === AcademicYear::STATUS_DRAFT;
    }

    public function close(User $user, AcademicYear $year): bool
    {
        return $this->allows($user, $year, 'academic_years.close')
            && $year->status !== AcademicYear::STATUS_ARCHIVED;
    }
}
