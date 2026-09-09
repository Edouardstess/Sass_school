<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Services\WebhookProcessor;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound payment provider notifications.
 *
 * Unauthenticated by necessity — providers cannot hold a Sanctum token — so
 * trust comes entirely from the signature check inside WebhookProcessor. This
 * controller does no business logic of its own; it exists to hand the raw body
 * and headers over unmodified, because a re-encoded body would not verify.
 *
 * Responses are deliberately terse and always 200 unless the signature is bad:
 * a provider that receives a 500 will retry, and a retry storm on a genuine
 * bug is worse than a logged failure we can replay from `webhook_events`.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly WebhookProcessor $processor) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        // The raw body, not $request->all(): the signature covers the exact
        // bytes the provider sent.
        $rawPayload = $request->getContent();

        try {
            $result = $this->processor->handle(
                provider: $provider,
                rawPayload: $rawPayload,
                headers: $this->normaliseHeaders($request),
                payload: json_decode($rawPayload, true) ?: [],
                ipAddress: $request->ip(),
            );

            return response()->json(['received' => true, 'status' => $result['status']], 200);
        } catch (DomainException $e) {
            // A failed signature check is the one case worth refusing loudly:
            // it means someone is sending us forged notifications.
            Log::warning('Payment webhook rejected', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['received' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            report($e);

            // The event is already persisted as `failed`, so it can be
            // replayed; a 200 stops the provider hammering us meanwhile.
            return response()->json(['received' => true, 'status' => 'deferred'], 200);
        }
    }

    /**
     * Lower-cased header map, since providers differ on casing and PHP does
     * not normalise it for us.
     *
     * @return array<string, string>
     */
    private function normaliseHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $values) {
            $headers[strtolower($key)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }
}
