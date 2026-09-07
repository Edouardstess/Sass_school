<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\PaymentTransaction;
use App\Domain\Finance\Models\WebhookEvent;
use App\Domain\Finance\ValueObjects\WebhookResult;
use App\Domain\School\Models\School;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Payments\PaymentGatewayManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single entry point for provider payment notifications.
 *
 * Four rules, all of them load-bearing:
 *
 *  1. **Authenticated.** The signature is verified before the body is looked
 *     at. An unsigned or wrongly signed request is rejected outright, and a
 *     provider with no configured secret can never be verified — so it can
 *     never settle anything.
 *  2. **Idempotent.** Every event is inserted into `webhook_events` first, on
 *     a unique (provider, event_id) key. A duplicate insert means we have seen
 *     this event, and it is acknowledged without being applied twice.
 *  3. **Verified.** For providers that support it, the transaction is
 *     re-queried directly before the payment is settled. The webhook says
 *     "look at this"; the provider's own API says what actually happened.
 *  4. **Logged.** The raw payload is retained for reconciliation and disputes.
 *
 * The result is that a forged, replayed or truncated notification cannot mark
 * an invoice paid.
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentService $payments,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array{status: string, message: string}
     */
    public function handle(
        string $provider,
        string $rawPayload,
        array $headers,
        array $payload,
        ?string $ipAddress = null,
    ): array {
        if (! $this->gateways->has($provider)) {
            throw new DomainException(__('finance.unknown_gateway', ['gateway' => $provider]));
        }

        $gateway = $this->gateways->get($provider);

        // (1) Authenticate before trusting a single field of the body.
        if (! $gateway->verifyWebhookSignature($rawPayload, $headers)) {
            Log::warning('Rejected payment webhook with invalid signature', [
                'provider' => $provider,
                'ip' => $ipAddress,
            ]);

            throw new DomainException(__('finance.webhook_invalid_signature'));
        }

        $result = $gateway->parseWebhook($payload);

        if ($result->eventId === '') {
            throw new DomainException(__('finance.webhook_unknown_transaction'));
        }

        // (2) Claim the event. The unique index is the idempotency mechanism;
        // a duplicate is a success, not an error — providers retry by design.
        try {
            $event = WebhookEvent::create([
                'provider' => $provider,
                'external_event_id' => $result->eventId,
                'event_type' => $result->eventType,
                'payload' => $payload,
                'signature' => substr((string) ($headers['stripe-signature'] ?? $headers['x-moncash-signature'] ?? $headers['x-natcash-signature'] ?? ''), 0, 512),
                'ip_address' => $ipAddress,
                'status' => WebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'duplicate', 'message' => 'Event already processed.'];
        }

        try {
            $outcome = $this->apply($gateway->key(), $result);

            $event->forceFill([
                'status' => $outcome === 'ignored' ? WebhookEvent::STATUS_IGNORED : WebhookEvent::STATUS_PROCESSED,
                'processed_at' => now(),
                'attempts' => $event->attempts + 1,
            ])->save();

            return ['status' => $outcome, 'message' => 'Event processed.'];
        } catch (Throwable $e) {
            $event->forceFill([
                'status' => WebhookEvent::STATUS_FAILED,
                'error' => $e->getMessage(),
                'attempts' => $event->attempts + 1,
            ])->save();

            throw $e;
        }
    }

    /**
     * Apply a verified event to the matching transaction.
     *
     * The transaction is looked up across tenants (a webhook arrives with no
     * school context), and the tenant is then established from the
     * transaction's own `school_id` — never from anything in the payload.
     */
    private function apply(string $provider, WebhookResult $result): string
    {
        if (! $result->isActionable()) {
            return 'ignored';
        }

        if ($result->transactionReference === null) {
            throw new DomainException(__('finance.webhook_unknown_transaction'));
        }

        $transaction = PaymentTransaction::query()
            ->withoutTenantScope()
            ->where('provider', $provider)
            ->where('provider_reference', $result->transactionReference)
            ->first();

        if ($transaction === null) {
            // Not an error: the same endpoint may legitimately receive events
            // for transactions this instance did not create.
            Log::info('Payment webhook referenced an unknown transaction', [
                'provider' => $provider,
                'reference' => $result->transactionReference,
            ]);

            return 'ignored';
        }

        $school = School::query()->findOrFail($transaction->school_id);

        return $this->tenant->runFor($school, function () use ($transaction, $result, $provider): string {
            // (3) Trust the provider's own API over the notification body.
            $authoritative = $this->reconfirm($provider, $result);

            if ($authoritative->isSuccessful()) {
                $this->assertAmountMatches($transaction, $authoritative);

                DB::transaction(fn () => $this->payments->confirmGatewayPayment(
                    $transaction,
                    $authoritative->transactionReference,
                ));

                return 'succeeded';
            }

            if (in_array($authoritative->status, ['failed', 'cancelled'], true)) {
                $this->payments->failGatewayPayment($transaction, $authoritative->failureReason);

                return $authoritative->status;
            }

            return 'ignored';
        });
    }

    /**
     * Re-query the provider. If the call itself fails we fall back to the
     * signed payload — which is still authenticated — rather than dropping a
     * genuine settlement because the provider's status API was briefly down.
     */
    private function reconfirm(string $provider, WebhookResult $result): WebhookResult
    {
        try {
            $fresh = $this->gateways->get($provider)->fetchStatus((string) $result->transactionReference);

            return $fresh->status === 'unknown' ? $result : $fresh;
        } catch (Throwable $e) {
            Log::warning('Could not re-query provider status; falling back to the signed payload', [
                'provider' => $provider,
                'reference' => $result->transactionReference,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    /**
     * Refuse to settle when the provider reports a different amount from the
     * one we asked for. A mismatch means either a tampered request or a bug,
     * and both deserve a human rather than a credited invoice.
     */
    private function assertAmountMatches(PaymentTransaction $transaction, WebhookResult $result): void
    {
        if ($result->amount === null) {
            return;
        }

        $expected = $transaction->money('amount_minor');

        if (! $result->amount->equals($expected)) {
            throw new DomainException(sprintf(
                'Amount mismatch on transaction %s: expected %s, provider reported %s.',
                (string) $transaction->provider_reference,
                (string) $expected,
                (string) $result->amount,
            ));
        }
    }
}
