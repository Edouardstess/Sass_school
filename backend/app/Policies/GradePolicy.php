<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\Grade;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

/**
 * Marks.
 *
 * The requirement is explicit: "a teacher may enter marks only for the classes
 * and subjects they are assigned to". So holding `grades.create` is necessary
 * but never sufficient — assignment is verified against `class_subjects`, and
 * a locked assessment or period stops even an assigned teacher.
 */
class GradePolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['grades.view', 'grades.view_own']);
    }

    public function view(User $user, Grade $grade): bool
    {
        if (! $this->sameTenant($user, $grade)) {
            return false;
        }

        if ($user->hasPermission('grades.view')) {
            return true;
        }

        if (! $user->hasPermission('grades.view_own')) {
            return false;
        }

        return app(StudentPolicy::class)->isRelated($user, $grade->student);
    }

    /** Creating a mark is authorised against the assessment it belongs to. */
    public function createFor(User $user, Assessment $assessment): bool
    {
        if (! $this->sameTenant($user, $assessment)) {
            return false;
        }

        if (! $user->hasPermission('grades.create')) {
            return false;
        }

        if (! $assessment->acceptsGrades()) {
            return false;
        }

        return $this->teachesAssessment($user, $assessment);
    }

    public function update(User $user, Grade $grade): bool
    {
        if (! $this->sameTenant($user, $grade) || ! $user->hasPermission('grades.update')) {
            return false;
        }

        $assessment = $grade->assessment;

        if ($assessment === null || ! $assessment->acceptsGrades()) {
            return false;
        }

        return $this->teachesAssessment($user, $assessment);
    }

    public function delete(User $user, Grade $grade): bool
    {
        return $this->update($user, $grade) && $user->hasPermission('grades.delete');
    }

    public function lock(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment, 'grades.lock');
    }

    /**
     * A teacher must be assigned to the (class, subject) pair. Anyone holding
     * an administrative grade permission — a principal reviewing marks, for
     * instance — is not bound by the assignment, because they are not the one
     * teaching.
     */
    private function teachesAssessment(User $user, Assessment $assessment): bool
    {
        if ($user->hasPermission('grades.lock') || $user->hasPermission('grades.publish')) {
            return true;
        }

        $teacher = $user->relationLoaded('teacher') ? $user->teacher : $user->teacher()->first();

        if ($teacher === null) {
            // Not a teacher and not an administrator: no path to writing marks.
            return false;
        }

        return $teacher->teaches($assessment->class_subject_id);
    }
}
