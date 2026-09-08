<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\School\Models\School;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for the request.
 *
 * For an ordinary user the tenant is simply their own school — it is never
 * taken from a header, a subdomain or a body field, because anything the
 * client controls is something an attacker controls.
 *
 * A platform super admin has no school of their own, so they *may* name one
 * with `X-School-Id`; that path additionally requires the `platform.impersonate`
 * permission and is flagged in the audit trail.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->isPlatformAdmin()) {
            $school = $user->loadMissing('school')->school;

            if ($school === null) {
                return response()->json([
                    'success' => false,
                    'message' => __('tenancy.missing_school'),
                ], 403);
            }

            if (! $school->isOperational()) {
                return response()->json([
                    'success' => false,
                    'message' => __('tenancy.school_suspended'),
                    'code' => 'school_suspended',
                ], 403);
            }

            $this->tenant->set($school);

            return $next($request);
        }

        // ---- platform super admin ------------------------------------------
        $requestedSchoolId = $request->header('X-School-Id');

        if ($requestedSchoolId === null) {
            // No tenant: the request may only reach platform endpoints, which
            // do not go through the tenant-scoped route group.
            return $next($request);
        }

        if (! $user->hasPermission('platform.impersonate')) {
            return response()->json([
                'success' => false,
                'message' => __('tenancy.impersonation_forbidden'),
            ], 403);
        }

        $school = School::query()->find($requestedSchoolId);

        if ($school === null) {
            return response()->json([
                'success' => false,
                'message' => __('tenancy.unknown_school'),
            ], 404);
        }

        $this->tenant->set($school, impersonating: true);

        return $next($request);
    }
}
