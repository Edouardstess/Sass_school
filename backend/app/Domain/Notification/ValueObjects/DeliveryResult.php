<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObjects;

/**
 * The outcome of one delivery attempt, normalised across vendors.
 *
 * A failure is returned rather than thrown: one parent's dead phone number
 * must not abort a fan-out to two hundred others.
 */
final readonly class DeliveryResult
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public bool $successful,
        public string $provider,
        public ?string $messageId = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function sent(string $provider, ?string $messageId = null, array $raw = []): self
    {
        return new self(true, $provider, $messageId, null, $raw);
    }

    /** @param array<string, mixed> $raw */
    public static function failed(string $provider, string $error, array $raw = []): self
    {
        return new self(false, $provider, null, $error, $raw);
    }
}
