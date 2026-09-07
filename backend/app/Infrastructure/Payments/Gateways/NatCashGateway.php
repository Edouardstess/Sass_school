<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Gateways;

use App\Domain\Finance\Contracts\PaymentGatewayInterface;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\ValueObjects\GatewayCharge;
use App\Domain\Finance\ValueObjects\WebhookResult;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NatCash (Natcom Haiti).
 *
 * NatCash does not publish a stable public API specification the way MonCash
 * does; integrations are provisioned per merchant. Rather than invent
 * endpoints, this adapter is written against the merchant-API shape NatCash
 * provisions (base URL, merchant id and API key supplied with the merchant
 * account) and reads all three from configuration.
 *
 * Consequences, deliberately chosen:
 *   - with no credentials configured the gateway reports itself UNAVAILABLE,
 *     so the payment screen never offers a button that cannot work;
 *   - HMAC-SHA256 webhook verification is implemented and enforced;
 *   - `NATCASH_BASE_URL` is a configuration value, so pointing it at the URL
 *     on your merchant contract is the whole integration step.
 *
 * See docs/PAYMENTS.md for the checklist to go live.
 */
final class NatCashGateway implements PaymentGatewayInterface
{
    public function key(): string
    {
        return 'natcash';
    }

    public function displayName(): string
    {
        return 'NatCash';
    }

    public function isAvailable(): bool
    {
        return (bool) config('services.natcash.enabled')
            && config('services.natcash.merchant_id') !== null
            && config('services.natcash.api_key') !== null
            && config('services.natcash.base_url') !== null;
    }

    public function isManual(): bool
    {
        return false;
    }

    public function supportedCurrencies(): array
    {
        return ['HTG'];
    }

    public function initiate(Invoice $invoice, Money $amount, array $context = []): GatewayCharge
    {
        $this->assertConfigured();

        $response = Http::withHeaders([
            'X-Merchant-Id' => (string) config('services.natcash.merchant_id'),
            'Authorization' => 'Bearer '.config('services.natcash.api_key'),
        ])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post(rtrim((string) config('services.natcash.base_url'), '/').'/payments', [
                'amount' => (float) $amount->toDecimalString(),
                'currency' => $amount->currency,
                'reference' => $invoice->number,
                'description' => __('finance.invoice_payment_description', ['number' => $invoice->number]),
                'callback_url' => route('webhooks.payments', ['provider' => 'natcash']),
                'return_url' => rtrim((string) config('app.frontend_url'), '/')."/finance/invoices/{$invoice->id}",
                'customer_phone' => $context['payer_phone'] ?? null,
            ]);

        if ($response->failed()) {
            Log::warning('NatCash payment creation failed', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
            ]);

            throw new DomainException(__('finance.gateway_request_failed', ['gateway' => 'NatCash']));
        }

        $reference = data_get($response->json(), 'transaction_id');
        $checkoutUrl = data_get($response->json(), 'payment_url');

        if (! is_string($reference) || $reference === '') {
            throw new DomainException(__('finance.gateway_bad_response', ['gateway' => 'NatCash']));
        }

        return new GatewayCharge(
            provider: $this->key(),
            reference: $reference,
            amount: $amount,
            status: 'pending',
            checkoutUrl: is_string($checkoutUrl) ? $checkoutUrl : null,
            expiresAt: CarbonImmutable::now()->addMinutes(30),
            rawResponse: $response->json() ?? [],
        );
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $secret = config('services.natcash.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $provided = $headers['x-natcash-signature'] ?? $headers['X-NatCash-Signature'] ?? '';

        return hash_equals(hash_hmac('sha256', $rawPayload, $secret), (string) $provided);
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        return new WebhookResult(
            provider: $this->key(),
            eventId: (string) data_get($payload, 'event_id', data_get($payload, 'transaction_id', '')),
            eventType: (string) data_get($payload, 'event', 'payment.updated'),
            transactionReference: data_get($payload, 'transaction_id'),
            amount: $this->parseAmount($payload),
            status: $this->normaliseStatus((string) data_get($payload, 'status', '')),
            raw: $payload,
            failureReason: data_get($payload, 'failure_reason'),
        );
    }

    public function fetchStatus(string $providerReference): WebhookResult
    {
        $this->assertConfigured();

        $response = Http::withHeaders([
            'X-Merchant-Id' => (string) config('services.natcash.merchant_id'),
            'Authorization' => 'Bearer '.config('services.natcash.api_key'),
        ])
            ->acceptJson()
            ->timeout(20)
            ->get(rtrim((string) config('services.natcash.base_url'), '/')."/payments/{$providerReference}");

        if ($response->failed()) {
            return new WebhookResult(
                provider: $this->key(),
                eventId: $providerReference,
                eventType: 'payment.status',
                transactionReference: $providerReference,
                amount: null,
                status: 'unknown',
                raw: ['http_status' => $response->status()],
            );
        }

        return $this->parseWebhook($response->json() ?? []);
    }

    private function parseAmount(array $payload): ?Money
    {
        $raw = data_get($payload, 'amount');

        return $raw === null
            ? null
            : Money::fromDecimalString((string) $raw, (string) data_get($payload, 'currency', 'HTG'));
    }

    private function normaliseStatus(string $status): string
    {
        return match (strtolower($status)) {
            'success', 'successful', 'completed', 'paid' => 'succeeded',
            'failed', 'declined', 'error' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            '' => 'unknown',
            default => 'pending',
        };
    }

    private function assertConfigured(): void
    {
        if (! $this->isAvailable()) {
            throw new DomainException(__('finance.gateway_unavailable', ['gateway' => 'NatCash']));
        }
    }
}
