<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Document\Models\Certificate;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class CertificatePolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('certificates.view');
    }

    public function view(User $user, Certificate $certificate): bool
    {
        if (! $this->sameTenant($user, $certificate)) {
            return false;
        }

        if ($user->hasPermission('certificates.view')) {
            return true;
        }

        return app(StudentPolicy::class)->isRelated($user, $certificate->student);
    }

    public function issue(User $user): bool
    {
        return $user->hasPermission('certificates.issue');
    }

    public function revoke(User $user, Certificate $certificate): bool
    {
        return $this->allows($user, $certificate, 'certificates.revoke');
    }
}
