<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\LoginAttempt;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Sign-in, sign-out and the defences around them.
 *
 * Every path through this class writes a `login_attempts` row, whether it
 * succeeds or fails, because "who tried to get in and when" is exactly the
 * question you need answered after an incident.
 *
 * Two anti-enumeration rules are followed strictly:
 *   - the same generic message is returned whether the e-mail is unknown or
 *     the password is wrong;
 *   - `Hash::check` is still executed against a dummy hash for unknown users,
 *     so response timing does not reveal which addresses exist.
 */
final class AuthenticationService
{
    /**
     * A bcrypt hash of a value nobody knows, used to keep the timing of a
     * failed lookup indistinguishable from a wrong password.
     */
    private const TIMING_SAFE_DUMMY_HASH = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Authenticate and mint an API token.
     *
     * @return array{user: User, token: NewAccessToken}
     *
     * @throws ValidationException when credentials are rejected or throttled
     */
    public function login(
        string $email,
        string $password,
        string $ipAddress,
        ?string $userAgent = null,
        ?string $twoFactorCode = null,
        ?string $deviceName = null,
        bool $remember = false,
    ): array {
        $this->assertNotThrottled($email, $ipAddress);

        $user = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->with('school')
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            // Burn comparable time on the unknown-user path.
            if ($user === null) {
                Hash::check($password, self::TIMING_SAFE_DUMMY_HASH);
            }

            $this->recordAttempt($email, $ipAddress, $userAgent, false, LoginAttempt::REASON_INVALID_CREDENTIALS, $user);
            event(new Failed('web', $user, ['email' => $email]));

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->isActive()) {
            $this->recordAttempt($email, $ipAddress, $userAgent, false, LoginAttempt::REASON_ACCOUNT_DISABLED, $user);

            throw ValidationException::withMessages([
                'email' => [__('auth.account_disabled')],
            ]);
        }

        // A suspended tenant locks out its own users, but never the platform
        // admins, who may still need to get in to fix the situation.
        if (! $user->isPlatformAdmin() && $user->school?->isOperational() !== true) {
            $this->recordAttempt($email, $ipAddress, $userAgent, false, LoginAttempt::REASON_SCHOOL_SUSPENDED, $user);

            throw ValidationException::withMessages([
                'email' => [__('auth.school_suspended')],
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            if ($twoFactorCode === null) {
                $this->recordAttempt($email, $ipAddress, $userAgent, false, LoginAttempt::REASON_TWO_FACTOR_REQUIRED, $user);

                throw ValidationException::withMessages([
                    'two_factor_code' => [__('auth.two_factor_required')],
                ]);
            }

            if (! $this->twoFactor->verify($user, $twoFactorCode)) {
                $this->recordAttempt($email, $ipAddress, $userAgent, false, LoginAttempt::REASON_TWO_FACTOR_FAILED, $user);

                throw ValidationException::withMessages([
                    'two_factor_code' => [__('auth.two_factor_invalid')],
                ]);
            }
        }

        $token = $this->issueToken($user, $deviceName, $remember);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ipAddress,
        ])->save();

        $this->recordAttempt($email, $ipAddress, $userAgent, true, null, $user);
        $this->audit->authentication(AuditAction::Login, $user, $user->school_id, [
            'description' => 'Successful sign-in',
            'device_name' => $deviceName,
        ]);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Mint a Sanctum token.
     *
     * Token abilities mirror the user's effective permissions, so a stolen
     * token can never do more than its owner could — and a token issued
     * before a permission was granted does not silently gain it.
     */
    public function issueToken(User $user, ?string $deviceName, bool $remember = false): NewAccessToken
    {
        $expiresAt = $this->tokenExpiry($remember);

        return $user->createToken(
            $deviceName ?: 'api',
            $user->permissionNames(),
            $expiresAt,
        );
    }

    public function logout(User $user, ?string $currentTokenId = null, bool $allDevices = false): void
    {
        if ($allDevices) {
            $user->tokens()->delete();
        } elseif ($currentTokenId !== null) {
            $user->tokens()->whereKey($currentTokenId)->delete();
        }

        $this->audit->authentication(AuditAction::Logout, $user, $user->school_id, [
            'description' => $allDevices ? 'Signed out of all devices' : 'Signed out',
        ]);
    }

    /**
     * Change a password, invalidating every existing token.
     *
     * Revoking tokens is the point: a password change is usually a response to
     * a suspected compromise, and leaving old sessions alive would defeat it.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('auth.current_password_invalid')],
            ]);
        }

        if (Hash::check($newPassword, $user->password)) {
            throw new DomainException(__('auth.password_must_differ'));
        }

        $user->forceFill([
            'password' => $newPassword,
            'password_changed_at' => now(),
        ])->save();

        $user->tokens()->delete();

        $this->audit->authentication(AuditAction::PasswordChange, $user, $user->school_id, [
            'description' => 'Password changed; all sessions revoked',
        ]);
    }

    /**
     * Reject the request when too many recent attempts failed.
     *
     * Counted per (e-mail, IP) pair rather than per e-mail alone: locking on
     * e-mail only would let an attacker lock a victim out of their own
     * account, turning the defence into a denial of service.
     */
    private function assertNotThrottled(string $email, string $ipAddress): void
    {
        $window = (int) config('schoolflow.security.lockout_seconds', 900);
        $max = (int) config('schoolflow.security.max_login_attempts', 5);

        $recentFailures = LoginAttempt::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->where('ip_address', $ipAddress)
            ->where('successful', false)
            ->where('created_at', '>=', Carbon::now()->subSeconds($window))
            ->count();

        if ($recentFailures >= $max) {
            $this->recordAttempt($email, $ipAddress, null, false, LoginAttempt::REASON_THROTTLED, null);

            throw ValidationException::withMessages([
                'email' => [__('auth.throttled', ['seconds' => $window])],
            ]);
        }
    }

    private function recordAttempt(
        string $email,
        string $ipAddress,
        ?string $userAgent,
        bool $successful,
        ?string $reason,
        ?User $user,
    ): void {
        LoginAttempt::create([
            'user_id' => $user?->id,
            'school_id' => $user?->school_id,
            'email' => $email,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? substr($userAgent, 0, 1000) : null,
            'successful' => $successful,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function tokenExpiry(bool $remember): ?Carbon
    {
        $minutes = config('schoolflow.security.token_expiration_minutes');

        if ($minutes === null) {
            return null;
        }

        // "Remember this device" extends the token, it does not make it eternal.
        return Carbon::now()->addMinutes($remember ? (int) $minutes * 4 : (int) $minutes);
    }

    /** Look up the tenant a self-service registration is joining. */
    public function resolveSchoolBySlug(string $slug): School
    {
        return School::query()->where('slug', $slug)->firstOr(
            fn () => throw new DomainException(__('auth.unknown_school'))
        );
    }
}
