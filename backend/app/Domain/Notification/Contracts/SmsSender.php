<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\ValueObjects\DeliveryResult;

/**
 * Outbound SMS.
 *
 * The notification domain depends on this interface, never on a vendor, so
 * swapping Twilio for a local Haitian aggregator is a container binding rather
 * than a change to any business rule.
 */
interface SmsSender
{
    /** Vendor key recorded on the delivery log (`twilio`, `log`, …). */
    public function provider(): string;

    /** False when credentials are missing; callers must not attempt a send. */
    public function isConfigured(): bool;

    /**
     * @param  string  $to  E.164 where possible; implementations normalise
     */
    public function send(string $to, string $message): DeliveryResult;
}
