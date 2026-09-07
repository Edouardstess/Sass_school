<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * How a payment reached the school.
 *
 * `isManual()` separates methods an accountant records after the fact (cash on
 * the counter, a bank slip) from methods confirmed by a provider webhook.
 * Manual methods are trusted because a human with `payments.create` vouched
 * for them; gateway methods are trusted only after signature verification.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case MonCash = 'moncash';
    case NatCash = 'natcash';
    case Stripe = 'stripe';

    public function isManual(): bool
    {
        return in_array($this, [self::Cash, self::BankTransfer, self::Cheque], true);
    }

    public function isGateway(): bool
    {
        return ! $this->isManual();
    }

    public function gatewayKey(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::BankTransfer => 'Virement bancaire',
            self::Cheque => 'Chèque',
            self::MonCash => 'MonCash',
            self::NatCash => 'NatCash',
            self::Stripe => 'Carte bancaire',
        };
    }
}
