<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Request throttling.
 *
 * Limits are per-user when authenticated and per-IP otherwise, so one noisy
 * tenant cannot exhaust another's allowance, and an unauthenticated attacker
 * cannot spend a legitimate user's budget.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return $request->user()
                ? Limit::perMinute(120)->by('user:'.$request->user()->id)
                : Limit::perMinute(30)->by('ip:'.$request->ip());
        });

        // Sign-in is the endpoint an attacker hammers, so it gets its own,
        // much tighter bucket keyed on both the address tried and the source.
        RateLimiter::for('auth', function (Request $request): array {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by('auth:'.mb_strtolower($email).'|'.$request->ip()),
                Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
            ];
        });

        // Password reset e-mails are an amplification vector; keep them slow.
        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perHour(5)
            ->by('pwd:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        // The public admissions form takes anonymous writes: keep it usable
        // for a family filling in three siblings, hostile to a bot.
        RateLimiter::for('public-write', fn (Request $request): Limit => Limit::perHour(10)
            ->by('public:'.$request->ip()));

        // Document verification is public and read-only, but must not become
        // a code-guessing oracle.
        RateLimiter::for('verification', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('verify:'.$request->ip()));

        // The AI assistant costs money per call; bill it to the user.
        RateLimiter::for('assistant', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('ai:'.($request->user()->id ?? $request->ip())));

        // Exports and imports are heavy; a handful per hour is plenty.
        RateLimiter::for('heavy', fn (Request $request): Limit => Limit::perHour(30)
            ->by('heavy:'.($request->user()->id ?? $request->ip())));
    }
}
