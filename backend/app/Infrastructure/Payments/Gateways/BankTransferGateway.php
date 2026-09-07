<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateways;

/**
 * A transfer confirmed by the school's own bank statement. The accountant
 * records it with the bank reference, and usually attaches the slip as a
 * document on the payment.
 */
final class BankTransferGateway extends ManualGateway
{
    public function key(): string
    {
        return 'bank_transfer';
    }

    public function displayName(): string
    {
        return 'Virement bancaire';
    }
}
