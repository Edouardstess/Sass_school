<?php

declare(strict_types=1);

namespace App\Domain\Finance\Contracts;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\ValueObjects\GatewayCharge;
use App\Domain\Finance\ValueObjects\WebhookResult;
use App\Domain\Shared\ValueObjects\Money;

/**
 * The only contract the finance domain knows about payments.
 *
 * Nothing in `Domain\Finance` may reference MonCash, NatCash or Stripe by
 * name: providers are swapped by binding a different implementation, and a
 * provider without credentials reports itself unavailable rather than
 * pretending to work.
 */
interface PaymentGatewayInterface
{
    /** Stable key: `cash`, `moncash`, `stripe`… Matches PaymentMethod values. */
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether this provider can actually be used right now.
     *
     * False when credentials are missing. Callers must check before
     * initiating: an unconfigured provider must fail loudly at the boundary,
     * never silently produce a fake success.
     */
    public function isAvailable(): bool;

    /** @return list<string> ISO-4217 codes this provider settles in. */
    public function supportedCurrencies(): array;

    /**
     * True when a human records the payment (cash, bank slip) rather than a
     * provider confirming it. Manual gateways settle immediately; automated
     * ones settle only on a verified webhook.
     */
    public function isManual(): bool;

    /**
     * Begin a charge.
     *
     * For an automated provider this creates a remote transaction and returns
     * a checkout URL. For a manual provider it returns an immediately settled
     * charge. It NEVER marks an invoice paid by itself — the caller applies
     * the result through PaymentService.
     */
    public function initiate(Invoice $invoice, Money $amount, array $context = []): GatewayCharge;

    /**
     * Verify a webhook's authenticity.
     *
     * Implementations must use a constant-time comparison and must return
     * false when no secret is configured — an unverifiable webhook is not a
     * trusted one.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool;

    /**
     * Translate a verified webhook body into a domain-level result.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): WebhookResult;

    /**
     * Re-query the provider for the authoritative status of a transaction.
     *
     * Used to confirm a payment independently of the webhook body, so a forged
     * or replayed notification cannot settle an invoice on its own.
     */
    public function fetchStatus(string $providerReference): WebhookResult;
}
