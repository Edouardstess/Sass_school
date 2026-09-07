<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the tenant-scoped route group.
 *
 * Without this, a platform admin who forgot the `X-School-Id` header would
 * reach tenant controllers with the global scope disabled — and see every
 * school's data at once. Failing the request is the safe outcome.
 */
final class EnsureTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenant->has()) {
            return response()->json([
                'success' => false,
                'message' => __('tenancy.context_required'),
                'code' => 'tenant_context_required',
            ], 400);
        }

        return $next($request);
    }
}
