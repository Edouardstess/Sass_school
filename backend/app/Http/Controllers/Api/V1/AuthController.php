<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\AuthenticationService;
use App\Domain\Identity\Services\TwoFactorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication endpoints.
 *
 * Sign-in is throttled by the `auth` limiter (see RateLimitServiceProvider),
 * which buckets on both the address tried and the source IP.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            ipAddress: (string) $request->ip(),
            userAgent: $request->userAgent(),
            twoFactorCode: $request->input('two_factor_code'),
            deviceName: $request->input('device_name'),
            remember: $request->boolean('remember'),
        );

        $user = $result['user']->load(['roles', 'school']);

        return ApiResponse::success([
            'token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $result['token']->accessToken->expires_at,
            'user' => new UserResource($user),
        ], __('auth.login_successful'));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $this->auth->logout(
            $user,
            $user->currentAccessToken()->getKey(),
            $request->boolean('all_devices'),
        );

        return ApiResponse::success(null, __('auth.logout_successful'));
    }

    /** The signed-in user, with the permission list the frontend gates on. */
    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request)->load(['roles', 'school']);

        return ApiResponse::success([
            'user' => new UserResource($user),
            'permissions' => $user->permissionNames(),
            'roles' => $user->roles->pluck('name'),
            'school' => $user->school !== null ? [
                'id' => $user->school->id,
                'name' => $user->school->name,
                'slug' => $user->school->slug,
                'currency' => $user->school->currency,
                'locale' => $user->school->locale,
                'logo_url' => $user->school->logo_path,
            ] : null,
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword(
            $this->user($request),
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
        );

        // Every token was revoked, so the client must sign in again.
        return ApiResponse::success(null, __('auth.password_changed'));
    }

    // ------------------------------------------------------------------- 2FA

    public function enableTwoFactor(Request $request): JsonResponse
    {
        $enrollment = $this->twoFactor->beginEnrollment(
            $this->user($request),
            (string) config('app.name'),
        );

        // The secret and recovery codes are shown exactly once, at enrolment.
        return ApiResponse::success($enrollment, __('auth.two_factor_pending_confirmation'));
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        $this->twoFactor->confirm($this->user($request), $request->string('code')->toString());

        return ApiResponse::success(null, __('auth.two_factor_enabled'));
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        // Disabling a second factor is a security downgrade, so the password
        // is required again even though the session is already authenticated.
        $request->validate(['password' => ['required', 'string']]);

        $user = $this->user($request);

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            return ApiResponse::error(__('auth.current_password_invalid'), 422, [
                'password' => [__('auth.current_password_invalid')],
            ]);
        }

        $this->twoFactor->disable($user);

        return ApiResponse::success(null, __('auth.two_factor_disabled'));
    }
}
