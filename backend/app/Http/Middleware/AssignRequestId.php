<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a correlation id to every request, echoes it in `X-Request-Id`, and
 * pushes it into the log context.
 *
 * When a parent reports "my payment failed at about 2pm", this is what turns
 * that into a single grep across the API logs, the queue logs and the audit
 * trail.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Honour an upstream id when the proxy set one, so a trace survives
        // across service boundaries; otherwise mint one.
        $requestId = $request->header('X-Request-Id');

        if ($requestId === null || ! preg_match('/^[A-Za-z0-9\-]{8,64}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
