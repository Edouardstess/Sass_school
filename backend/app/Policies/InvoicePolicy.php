<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class InvoicePolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['invoices.view', 'invoices.view_own', 'finance.view']);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $this->sameTenant($user, $invoice)) {
            return false;
        }

        if ($user->hasAnyPermission(['invoices.view', 'finance.view'])) {
            return true;
        }

        if (! $user->hasPermission('invoices.view_own')) {
            return false;
        }

        return app(StudentPolicy::class)->isRelated($user, $invoice->student);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('invoices.create');
    }

    /** Only a draft is editable; an issued invoice is an accounting document. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->allows($user, $invoice, 'invoices.update')
            && $invoice->status->isEditable();
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->allows($user, $invoice, 'invoices.issue')
            && $invoice->status->isEditable();
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->allows($user, $invoice, 'invoices.cancel')
            && ! $invoice->status->isFinal();
    }

    /** Deleting is only ever permitted while nothing has been paid. */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->allows($user, $invoice, 'invoices.update')
            && $invoice->status->isEditable()
            && $invoice->paid_minor === 0;
    }

    /** A guardian may start an online payment for their own child's invoice. */
    public function pay(User $user, Invoice $invoice): bool
    {
        if (! $this->sameTenant($user, $invoice) || ! $invoice->status->canAcceptPayment()) {
            return false;
        }

        if ($user->hasPermission('payments.create') && $user->hasPermission('finance.view')) {
            return true;
        }

        return $user->hasPermission('payments.create')
            && app(StudentPolicy::class)->isRelated($user, $invoice->student);
    }
}
