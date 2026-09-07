<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every API response.
 *
 * Nginx sets these too; doing it here as well means they survive if the API is
 * ever fronted by something else, and keeps `php artisan serve` honest during
 * development.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            // The API serves JSON only: nothing should ever be loaded from it.
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Cross-Origin-Resource-Policy' => 'same-site',
        ];

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        // Responses carrying personal data must not be cached by proxies.
        if (! $response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
