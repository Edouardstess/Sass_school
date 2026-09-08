<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\ValueObjects\DeliveryResult;

/** Outbound WhatsApp, same contract shape as SMS. */
interface WhatsAppSender
{
    public function provider(): string;

    public function isConfigured(): bool;

    public function send(string $to, string $message): DeliveryResult;
}
