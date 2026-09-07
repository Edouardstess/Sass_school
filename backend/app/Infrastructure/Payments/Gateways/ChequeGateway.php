<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateways;

final class ChequeGateway extends ManualGateway
{
    public function key(): string
    {
        return 'cheque';
    }

    public function displayName(): string
    {
        return 'Chèque';
    }
}
