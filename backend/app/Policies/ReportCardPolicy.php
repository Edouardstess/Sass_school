<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Academic\Models\ReportCard;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class ReportCardPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['report_cards.view', 'report_cards.view_own']);
    }

    public function view(User $user, ReportCard $card): bool
    {
        if (! $this->sameTenant($user, $card)) {
            return false;
        }

        if ($user->hasPermission('report_cards.view')) {
            return true;
        }

        if (! $user->hasPermission('report_cards.view_own')) {
            return false;
        }

        // A draft card is a work in progress: parents see it only once the
        // school has published it.
        return $card->isPublished() && app(StudentPolicy::class)->isRelated($user, $card->student);
    }

    public function generate(User $user): bool
    {
        return $user->hasPermission('report_cards.generate');
    }

    public function publish(User $user, ReportCard $card): bool
    {
        return $this->allows($user, $card, 'report_cards.publish');
    }
}
