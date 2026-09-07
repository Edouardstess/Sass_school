<?php

declare(strict_types=1);

namespace App\Domain\Finance\ValueObjects;

use App\Domain\Shared\ValueObjects\Money;

/**
 * A provider notification, normalised.
 *
 * `eventId` is the provider's own event identifier and is what the
 * `webhook_events` unique key is built on — it is what makes replays and
 * provider retries harmless.
 */
final readonly class WebhookResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $eventType,
        public ?string $transactionReference,
        public ?Money $amount,
        /** succeeded | failed | pending | cancelled | unknown */
        public string $status,
        public array $raw = [],
        public ?string $failureReason = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }

    /** Events we understand but that require no action are ignored, not failed. */
    public function isActionable(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'cancelled'], true);
    }
}
