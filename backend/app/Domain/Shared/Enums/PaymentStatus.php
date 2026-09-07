<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Payment lifecycle. Only `Succeeded` moves money on an invoice — a payment
 * sitting in `Pending` or `Processing` has no effect on the balance, which is
 * what makes an unconfirmed gateway redirect harmless.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    public function affectsBalance(): bool
    {
        return $this === self::Succeeded;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Refunded], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Succeeded, self::Failed, self::Cancelled],
            self::Processing => [self::Succeeded, self::Failed, self::Cancelled],
            self::Succeeded => [self::Refunded],
            self::Failed, self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
