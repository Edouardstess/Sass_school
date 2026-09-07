<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateways;

/** Cash handed over at the school office. */
final class CashGateway extends ManualGateway
{
    public function key(): string
    {
        return 'cash';
    }

    public function displayName(): string
    {
        return 'Espèces';
    }
}
