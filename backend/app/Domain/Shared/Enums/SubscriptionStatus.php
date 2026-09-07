<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** The tenant may use the product. */
    public function isUsable(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /** Counts towards monthly recurring revenue. */
    public function isBillable(): bool
    {
        return in_array($this, [self::Active, self::PastDue], true);
    }

    /** Counts as churn when it happens during a period. */
    public function isChurned(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], true);
    }
}
