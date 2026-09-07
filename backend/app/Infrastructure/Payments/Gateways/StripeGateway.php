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
 * Stripe Checkout, for schools billing internationally in USD.
 *
 * Called over Stripe's REST API directly rather than through the SDK, so the
 * dependency surface stays small and the exact requests are visible here.
 *
 * Two details that matter:
 *   - Stripe's API is already denominated in minor units, so `amount_minor`
 *     is passed straight through with no conversion and no rounding;
 *   - the webhook signature check implements Stripe's `Stripe-Signature`
 *     scheme, including the timestamp tolerance that stops a captured payload
 *     from being replayed days later.
 */
final class StripeGateway implements PaymentGatewayInterface
{
    private const API_BASE = 'https://api.stripe.com/v1';

    /** Reject signatures older than this, per Stripe's guidance. */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function key(): string
    {
        return 'stripe';
    }

    public function displayName(): string
    {
        return 'Carte bancaire (Stripe)';
    }

    public function isAvailable(): bool
    {
        return (bool) config('services.stripe.enabled')
            && config('services.stripe.secret') !== null;
    }

    public function isManual(): bool
    {
        return false;
    }

    public function supportedCurrencies(): array
    {
        return ['USD', 'HTG'];
    }

    public function initiate(Invoice $invoice, Money $amount, array $context = []): GatewayCharge
    {
        $this->assertConfigured();

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $response = Http::withToken((string) config('services.stripe.secret'))
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->post(self::API_BASE.'/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => $invoice->id,
                'success_url' => $frontend."/finance/invoices/{$invoice->id}?payment=complete",
                'cancel_url' => $frontend."/finance/invoices/{$invoice->id}?payment=cancelled",
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => strtolower($amount->currency),
                // Already minor units on both sides: no conversion, no rounding.
                'line_items[0][price_data][unit_amount]' => $amount->minorUnits,
                'line_items[0][price_data][product_data][name]' => __('finance.invoice_payment_description', [
                    'number' => $invoice->number,
                ]),
                'metadata[invoice_id]' => $invoice->id,
                'metadata[invoice_number]' => $invoice->number,
                'metadata[school_id]' => (string) $invoice->school_id,
            ]);

        if ($response->failed()) {
            Log::warning('Stripe checkout session creation failed', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
                'error' => data_get($response->json(), 'error.message'),
            ]);

            throw new DomainException(__('finance.gateway_request_failed', ['gateway' => 'Stripe']));
        }

        $session = $response->json();

        return new GatewayCharge(
            provider: $this->key(),
            reference: (string) data_get($session, 'id'),
            amount: $amount,
            status: 'pending',
            checkoutUrl: data_get($session, 'url'),
            expiresAt: ($expires = data_get($session, 'expires_at')) !== null
                ? CarbonImmutable::createFromTimestamp((int) $expires)
                : null,
            rawResponse: is_array($session) ? $session : [],
        );
    }

    /**
     * Stripe's signature scheme: `t=<timestamp>,v1=<hmac>` where the HMAC is
     * over "<timestamp>.<raw body>". Both the freshness check and the
     * constant-time comparison are required — dropping either turns a replayed
     * payload into a free payment.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? '';
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $header) as $part) {
            [$prefix, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($prefix === 't') {
                $timestamp = $value;
            } elseif ($prefix === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawPayload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        $type = (string) data_get($payload, 'type', '');
        $object = data_get($payload, 'data.object', []);

        $status = match ($type) {
            'checkout.session.completed' => data_get($object, 'payment_status') === 'paid' ? 'succeeded' : 'pending',
            'checkout.session.async_payment_succeeded', 'payment_intent.succeeded' => 'succeeded',
            'checkout.session.async_payment_failed', 'payment_intent.payment_failed' => 'failed',
            'checkout.session.expired' => 'cancelled',
            default => 'unknown',
        };

        $amountTotal = data_get($object, 'amount_total');
        $currency = data_get($object, 'currency');

        return new WebhookResult(
            provider: $this->key(),
            eventId: (string) data_get($payload, 'id'),
            eventType: $type,
            transactionReference: data_get($object, 'id'),
            amount: $amountTotal !== null && $currency !== null
                ? Money::of((int) $amountTotal, strtoupper((string) $currency))
                : null,
            status: $status,
            raw: $payload,
            failureReason: data_get($object, 'last_payment_error.message'),
        );
    }

    public function fetchStatus(string $providerReference): WebhookResult
    {
        $this->assertConfigured();

        $response = Http::withToken((string) config('services.stripe.secret'))
            ->acceptJson()
            ->timeout(20)
            ->get(self::API_BASE.'/checkout/sessions/'.$providerReference);

        if ($response->failed()) {
            return new WebhookResult(
                provider: $this->key(),
                eventId: $providerReference,
                eventType: 'checkout.session.status',
                transactionReference: $providerReference,
                amount: null,
                status: 'unknown',
                raw: ['http_status' => $response->status()],
            );
        }

        $session = $response->json();

        return new WebhookResult(
            provider: $this->key(),
            eventId: $providerReference,
            eventType: 'checkout.session.status',
            transactionReference: $providerReference,
            amount: ($total = data_get($session, 'amount_total')) !== null
                ? Money::of((int) $total, strtoupper((string) data_get($session, 'currency', 'usd')))
                : null,
            status: data_get($session, 'payment_status') === 'paid' ? 'succeeded' : 'pending',
            raw: is_array($session) ? $session : [],
        );
    }

    private function assertConfigured(): void
    {
        if (! $this->isAvailable()) {
            throw new DomainException(__('finance.gateway_unavailable', ['gateway' => 'Stripe']));
        }
    }
}
