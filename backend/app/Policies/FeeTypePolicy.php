<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\FeeType;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class FeeTypePolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['fee_types.manage', 'finance.view']);
    }

    public function view(User $user, FeeType $feeType): bool
    {
        return $this->sameTenant($user, $feeType)
            && $user->hasAnyPermission(['fee_types.manage', 'finance.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('fee_types.manage');
    }

    public function update(User $user, FeeType $feeType): bool
    {
        return $this->allows($user, $feeType, 'fee_types.manage');
    }

    public function delete(User $user, FeeType $feeType): bool
    {
        return $this->allows($user, $feeType, 'fee_types.manage');
    }
}
