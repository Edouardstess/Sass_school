<?php

declare(strict_types=1);

use App\Domain\Shared\Exceptions\CurrencyMismatchException;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\PlanLimitExceededException;
use App\Domain\Shared\Exceptions\TenantMismatchException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateSession;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id and security headers wrap absolutely everything.
        $middleware->append(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.required' => EnsureTenantContext::class,
            'permission' => EnsurePermission::class,
        ]);

        // Sanctum tokens are stateless; there is no session to protect.
        $middleware->statefulApi();

        /*
         * Middleware ordering matters for tenancy, not just for tidiness.
         *
         * Route-model binding resolves `{student}` through the model's query,
         * which carries the tenant global scope — but only if the tenant is
         * already established. Left at its default position, SubstituteBindings
         * runs *before* ResolveTenant, the binding resolves unscoped, and a
         * cross-tenant identifier reaches the policy and comes back 403.
         *
         * A 403 confirms the record exists somewhere, which is an enumeration
         * oracle across tenants. Placing the tenant middleware ahead of
         * SubstituteBindings makes the binding itself scoped, so another
         * school's identifier is indistinguishable from one that never existed.
         */
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            Authenticate::class,
            AuthenticateSession::class,
            ResolveTenant::class,
            EnsureTenantContext::class,
            SubstituteBindings::class,
            EnsurePermission::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Every API failure answers with the same envelope as every success,
         * so a client needs exactly one error path. Statuses are chosen so the
         * frontend can react meaningfully rather than showing "something went
         * wrong":
         *   402 → plan limit reached, show an upgrade prompt
         *   409 → business rule violated, show the domain message
         *   422 → field-level validation, map back onto the form
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => __('responses.validation_failed'),
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => __('auth.unauthenticated'),
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('auth.forbidden'),
                'code' => 'forbidden',
            ], 403);
        });

        // A record that exists in another tenant must look identical to one
        // that does not exist at all — otherwise the 403/404 split becomes an
        // oracle for probing other schools' identifiers.
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => __('responses.not_found'),
            ], 404);
        });

        $exceptions->render(function (TenantMismatchException $e, Request $request) {
            report($e); // never silently swallowed: this means a layer has a hole

            return response()->json([
                'success' => false,
                'message' => __('responses.not_found'),
            ], 404);
        });

        $exceptions->render(function (PlanLimitExceededException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'plan_limit_exceeded',
                'errors' => [],
                'meta' => [
                    'feature' => $e->feature,
                    'limit' => $e->limit,
                    'current' => $e->current,
                ],
            ], 402);
        });

        $exceptions->render(function (DomainException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'domain_rule_violated',
                'meta' => $e->context ?: null,
            ], 409);
        });

        $exceptions->render(function (CurrencyMismatchException $e, Request $request) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => __('responses.currency_mismatch'),
                'code' => 'currency_mismatch',
            ], 422);
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => __('responses.too_many_requests'),
                'code' => 'rate_limited',
            ], 429);
        });

        // Catch-all. In production the internal message is withheld; the
        // request id is returned instead so support can find it in the logs.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : __('responses.error'),
                ], $e->getStatusCode());
            }

            $debug = (bool) config('app.debug');

            return response()->json([
                'success' => false,
                'message' => $debug ? $e->getMessage() : __('responses.server_error'),
                'code' => 'server_error',
                'meta' => [
                    'request_id' => $request->attributes->get('request_id'),
                    ...($debug ? ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()] : []),
                ],
            ], 500);
        });
    })
    ->create();
