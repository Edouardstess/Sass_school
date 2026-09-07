<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Support\Str;

/**
 * TOTP two-factor authentication (RFC 6238), implemented directly rather than
 * pulled from a package, so there is no unverifiable dependency in the
 * authentication path.
 *
 * Codes are 6 digits over a 30-second step, checked against a ±1 step window
 * to tolerate clock drift. Comparison is constant-time.
 */
final class TwoFactorService
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Accept the previous and next step to survive modest clock skew. */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    /**
     * Begin enrolment: generate a secret and recovery codes, but do NOT
     * activate 2FA yet — activation waits for `confirm()`, so a user who loses
     * their authenticator mid-setup is not locked out.
     *
     * @return array{secret: string, otpauth_url: string, recovery_codes: list<string>}
     */
    public function beginEnrollment(User $user, string $issuer = 'SchoolFlow'): array
    {
        $secret = $this->generateSecret();
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_url' => $this->otpauthUrl($user, $secret, $issuer),
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /** Activate 2FA once the user has proved they can generate a valid code. */
    public function confirm(User $user, string $code): void
    {
        if ($user->two_factor_secret === null) {
            throw new DomainException(__('auth.two_factor_not_started'));
        }

        if (! $this->verifyTotp($user->two_factor_secret, $code)) {
            throw new DomainException(__('auth.two_factor_invalid'));
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /** Accepts either a TOTP code or one single-use recovery code. */
    public function verify(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        if ($this->verifyTotp($user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals($this->generateCode($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Burn a recovery code. Each may be used once, and using one removes it
     * from the stored list immediately.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalised = strtoupper(trim($code));

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $normalised)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    private function generateSecret(): string
    {
        // 160 bits, the size recommended by RFC 4226 for HMAC-SHA1.
        return $this->base32Encode(random_bytes(20));
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn (): string => strtoupper(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    private function otpauthUrl(User $user, string $secret, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($user->email),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /** HOTP as specified in RFC 4226, which TOTP builds on. */
    private function generateCode(string $secret, int $counter): string
    {
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $this->base32Decode($secret), true);

        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $truncated, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = rtrim(strtoupper($encoded), '=');
        $bits = '';

        foreach (str_split($encoded) as $char) {
            $index = strpos($alphabet, $char);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
