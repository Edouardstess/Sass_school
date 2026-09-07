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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MonCash (Digicel Haiti).
 *
 * Integration shape: OAuth2 client-credentials for a bearer token, then a
 * "create payment" call that returns a payment token, which the payer is
 * redirected to. Settlement is confirmed by re-querying the transaction — the
 * redirect back to the school is treated as a hint, never as proof.
 *
 * The endpoints and field names below follow MonCash's published business API.
 * They are kept in one place, and every response is defensively parsed, so a
 * change on their side surfaces as a clear failure rather than a wrong
 * balance. Without credentials the gateway reports itself unavailable instead
 * of pretending to work.
 */
final class MonCashGateway implements PaymentGatewayInterface
{
    private const SANDBOX_BASE = 'https://sandbox.moncashbutton.digicelgroup.com';

    private const LIVE_BASE = 'https://moncashbutton.digicelgroup.com';

    public function key(): string
    {
        return 'moncash';
    }

    public function displayName(): string
    {
        return 'MonCash';
    }

    public function isAvailable(): bool
    {
        return (bool) config('services.moncash.enabled')
            && config('services.moncash.client_id') !== null
            && config('services.moncash.client_secret') !== null;
    }

    public function isManual(): bool
    {
        return false;
    }

    /** MonCash settles in gourdes only. */
    public function supportedCurrencies(): array
    {
        return ['HTG'];
    }

    public function initiate(Invoice $invoice, Money $amount, array $context = []): GatewayCharge
    {
        $this->assertConfigured();

        // MonCash expects a major-unit amount; the conversion happens exactly
        // once, at the boundary, and never inside the domain.
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->baseUrl().'/Api/v1/CreatePayment', [
                'amount' => (float) $amount->toDecimalString(),
                'orderId' => $invoice->number,
            ]);

        if ($response->failed()) {
            Log::warning('MonCash CreatePayment failed', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
            ]);

            throw new DomainException(__('finance.gateway_request_failed', ['gateway' => 'MonCash']));
        }

        $token = data_get($response->json(), 'payment_token.token');

        if (! is_string($token) || $token === '') {
            throw new DomainException(__('finance.gateway_bad_response', ['gateway' => 'MonCash']));
        }

        return new GatewayCharge(
            provider: $this->key(),
            reference: $invoice->number,
            amount: $amount,
            status: 'pending',
            checkoutUrl: $this->baseUrl().'/Moncash-middleware/Payment/Redirect?token='.$token,
            expiresAt: CarbonImmutable::now()->addMinutes(30),
            rawResponse: $response->json() ?? [],
        );
    }

    /**
     * MonCash does not sign its notifications, so a shared secret configured
     * on both sides is compared in constant time. When no secret is set the
     * answer is false: an unverifiable notification is never trusted.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $secret = config('services.moncash.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $provided = $headers['x-moncash-signature'] ?? $headers['X-MonCash-Signature'] ?? '';

        return hash_equals(hash_hmac('sha256', $rawPayload, $secret), (string) $provided);
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        $transactionId = (string) data_get($payload, 'transactionId', data_get($payload, 'transaction_id', ''));
        $orderId = (string) data_get($payload, 'orderId', data_get($payload, 'order_id', ''));
        $message = (string) data_get($payload, 'message', '');

        return new WebhookResult(
            provider: $this->key(),
            // MonCash has no event id, so the transaction id doubles as one —
            // it is still unique per settlement, which is what idempotency
            // needs.
            eventId: $transactionId !== '' ? $transactionId : $orderId,
            eventType: 'payment.updated',
            transactionReference: $orderId !== '' ? $orderId : null,
            amount: $this->parseAmount($payload),
            status: $this->normaliseStatus($message),
            raw: $payload,
            failureReason: $message !== '' && $this->normaliseStatus($message) !== 'succeeded' ? $message : null,
        );
    }

    /**
     * Ask MonCash directly what happened.
     *
     * This is the authoritative check: the webhook only prompts us to look, it
     * never settles anything by itself.
     */
    public function fetchStatus(string $providerReference): WebhookResult
    {
        $this->assertConfigured();

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->baseUrl().'/Api/v1/RetrieveOrderPayment', ['orderId' => $providerReference]);

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

        $payment = data_get($response->json(), 'payment', []);

        return new WebhookResult(
            provider: $this->key(),
            eventId: (string) data_get($payment, 'transaction_id', $providerReference),
            eventType: 'payment.status',
            transactionReference: $providerReference,
            amount: $this->parseAmount($payment),
            status: $this->normaliseStatus((string) data_get($payment, 'message', '')),
            raw: is_array($payment) ? $payment : [],
        );
    }

    /**
     * Cache the bearer token for slightly less than its stated lifetime, so a
     * burst of payments does not mean a token request each time.
     */
    private function accessToken(): string
    {
        return Cache::remember('moncash:token', now()->addMinutes(50), function (): string {
            $response = Http::withBasicAuth(
                (string) config('services.moncash.client_id'),
                (string) config('services.moncash.client_secret'),
            )
                ->asForm()
                ->acceptJson()
                ->timeout(20)
                ->post($this->baseUrl().'/Api/oauth/token', [
                    'scope' => 'read,write',
                    'grant_type' => 'client_credentials',
                ]);

            $token = data_get($response->json(), 'access_token');

            if (! is_string($token) || $token === '') {
                throw new DomainException(__('finance.gateway_auth_failed', ['gateway' => 'MonCash']));
            }

            return $token;
        });
    }

    private function parseAmount(array $payload): ?Money
    {
        $raw = data_get($payload, 'cost', data_get($payload, 'amount'));

        if ($raw === null) {
            return null;
        }

        return Money::fromDecimalString((string) $raw, 'HTG');
    }

    private function normaliseStatus(string $message): string
    {
        return match (strtolower($message)) {
            'successful', 'success', 'completed' => 'succeeded',
            'failed', 'error' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            '' => 'unknown',
            default => 'pending',
        };
    }

    private function baseUrl(): string
    {
        return config('services.moncash.mode') === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
    }

    private function assertConfigured(): void
    {
        if (! $this->isAvailable()) {
            throw new DomainException(__('finance.gateway_unavailable', ['gateway' => 'MonCash']));
        }
    }
}
