<?php

declare(strict_types=1);

namespace App\Domain\Finance\ValueObjects;

use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;

/** The outcome of asking a provider to start collecting a payment. */
final readonly class GatewayCharge
{
    /** @param array<string, mixed> $rawResponse */
    public function __construct(
        public string $provider,
        public string $reference,
        public Money $amount,
        /** initiated | pending | succeeded | failed */
        public string $status,
        public ?string $checkoutUrl = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $rawResponse = [],
        public ?string $failureReason = null,
    ) {}

    /** True only for manual methods, which settle at the moment of recording. */
    public function isSettled(): bool
    {
        return $this->status === 'succeeded';
    }

    public function requiresRedirect(): bool
    {
        return $this->checkoutUrl !== null;
    }
}
