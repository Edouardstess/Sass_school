<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateways;

use App\Domain\Finance\Contracts\PaymentGatewayInterface;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\ValueObjects\GatewayCharge;
use App\Domain\Finance\ValueObjects\WebhookResult;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Str;
use LogicException;

/**
 * Shared behaviour for methods a human records rather than a provider
 * confirms: cash at the counter, a bank slip, a cheque.
 *
 * These are always available (they need no credentials) and settle at the
 * moment of recording — the trust comes from a staff member holding
 * `payments.create`, not from a remote system. They have no webhooks, and the
 * webhook methods here fail loudly rather than returning a convenient "true"
 * that would make forged notifications look verified.
 */
abstract class ManualGateway implements PaymentGatewayInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function isManual(): bool
    {
        return true;
    }

    public function supportedCurrencies(): array
    {
        return array_keys(config('schoolflow.currency.supported', ['HTG' => []]));
    }

    public function initiate(Invoice $invoice, Money $amount, array $context = []): GatewayCharge
    {
        return new GatewayCharge(
            provider: $this->key(),
            reference: strtoupper($this->key()).'-'.Str::upper(Str::random(12)),
            amount: $amount,
            status: 'succeeded',
            rawResponse: ['recorded_manually' => true, 'context' => $context],
        );
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        // No signature exists, so nothing can be verified. Returning true here
        // would let anyone POST a "cash payment received" and settle invoices.
        return false;
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        throw new LogicException(static::class.' does not receive webhooks.');
    }

    public function fetchStatus(string $providerReference): WebhookResult
    {
        throw new LogicException(static::class.' has no remote status to query.');
    }
}
