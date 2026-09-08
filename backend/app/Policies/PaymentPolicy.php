<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class PaymentPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['payments.view', 'finance.view', 'invoices.view_own']);
    }

    public function view(User $user, Payment $payment): bool
    {
        if (! $this->sameTenant($user, $payment)) {
            return false;
        }

        if ($user->hasAnyPermission(['payments.view', 'finance.view'])) {
            return true;
        }

        $student = $payment->student;

        return $student !== null
            && $user->hasPermission('invoices.view_own')
            && app(StudentPolicy::class)->isRelated($user, $student);
    }

    /** Recording a manual payment is a staff action, not a parent one. */
    public function create(User $user): bool
    {
        return $user->hasPermission('payments.create');
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $this->allows($user, $payment, 'payments.refund')
            && $payment->status->isSuccessful()
            && $payment->refundableAmount()->isPositive();
    }
}
