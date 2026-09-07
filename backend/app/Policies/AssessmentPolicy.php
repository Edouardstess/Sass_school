<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class AssessmentPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['grades.view', 'grades.view_own']);
    }

    public function view(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment, 'grades.view')
            || $this->allows($user, $assessment, 'grades.view_own');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('grades.create');
    }

    /**
     * A teacher may only reshape their own assessments, and only while the
     * period is open.
     */
    public function update(User $user, Assessment $assessment): bool
    {
        if (! $this->allows($user, $assessment, 'grades.update') || $assessment->isLocked()) {
            return false;
        }

        return $this->ownsOrSupervises($user, $assessment);
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        return $this->update($user, $assessment)
            && $user->hasPermission('grades.delete')
            && $assessment->grades()->count() === 0;
    }

    public function lock(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment, 'grades.lock');
    }

    private function ownsOrSupervises(User $user, Assessment $assessment): bool
    {
        if ($user->hasAnyPermission(['grades.lock', 'grades.publish'])) {
            return true;
        }

        $teacher = $user->relationLoaded('teacher') ? $user->teacher : $user->teacher()->first();

        return $teacher !== null && $teacher->teaches($assessment->class_subject_id);
    }
}
