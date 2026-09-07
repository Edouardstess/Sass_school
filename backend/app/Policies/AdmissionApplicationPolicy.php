<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Student\Models\AdmissionApplication;
use App\Policies\Concerns\ChecksTenant;

class AdmissionApplicationPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admissions.view');
    }

    public function view(User $user, AdmissionApplication $application): bool
    {
        return $this->allows($user, $application, 'admissions.view');
    }

    public function review(User $user, AdmissionApplication $application): bool
    {
        return $this->allows($user, $application, 'admissions.review');
    }

    public function decide(User $user, AdmissionApplication $application): bool
    {
        return $this->allows($user, $application, 'admissions.decide')
            && $application->status->isOpen();
    }

    /** Conversion runs once; a converted application refuses a second run. */
    public function enroll(User $user, AdmissionApplication $application): bool
    {
        return $this->allows($user, $application, 'admissions.enroll')
            && ! $application->isConverted();
    }
}
