<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission gate: `->middleware('permission:students.create')`.
 *
 * This is a coarse first filter. It never replaces a policy — policies also
 * check ownership and tenancy — but it keeps a request that could not possibly
 * be authorised from reaching a controller at all.
 *
 * Several permissions may be listed; holding any one of them is enough
 * (`permission:invoices.view,invoices.view_own`).
 */
final class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => __('auth.unauthenticated'),
            ], 401);
        }

        if (! $user->hasAnyPermission($permissions)) {
            return response()->json([
                'success' => false,
                'message' => __('auth.forbidden'),
                'code' => 'insufficient_permissions',
            ], 403);
        }

        return $next($request);
    }
}
