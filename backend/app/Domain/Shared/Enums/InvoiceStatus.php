<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Invoice lifecycle.
 *
 *   DRAFT ──issue──► ISSUED ──payment──► PARTIALLY_PAID ──payment──► PAID
 *      │                │                      │
 *      └──cancel────────┴──────────────────────┴──► CANCELLED
 *                       └──due date passes──► OVERDUE ──payment──► PAID
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    /** Statuses that still owe money and therefore appear in receivables. */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid, self::Overdue], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }

    /** A draft is still editable; anything issued is an accounting document. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canAcceptPayment(): bool
    {
        return $this->isOutstanding();
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Issued, self::Cancelled],
            self::Issued => [self::PartiallyPaid, self::Paid, self::Overdue, self::Cancelled],
            self::PartiallyPaid => [self::Paid, self::Overdue, self::Cancelled],
            self::Overdue => [self::PartiallyPaid, self::Paid, self::Cancelled],
            self::Paid, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $target === $this || in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Issued => 'Émise',
            self::PartiallyPaid => 'Partiellement payée',
            self::Paid => 'Payée',
            self::Overdue => 'En retard',
            self::Cancelled => 'Annulée',
        };
    }
}
