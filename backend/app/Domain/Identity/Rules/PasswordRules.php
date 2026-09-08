<?php

declare(strict_types=1);

namespace App\Domain\Identity\Rules;

use Illuminate\Validation\Rules\Password;

/**
 * The one definition of what makes an acceptable password.
 *
 * `uncompromised()` checks the candidate against the Have I Been Pwned
 * k-anonymity range API. That is a genuine control — it is the single most
 * effective password rule there is — but it means an outbound HTTP call, so it
 * is switched off in `testing`: a test suite that depends on a third-party
 * service is a test suite that fails when someone else has an outage, and it
 * would silently exercise the network from CI.
 *
 * In every other environment the check is on. Laravel's verifier fails open
 * when the service is unreachable, so an HIBP outage cannot stop a parent from
 * changing their password.
 */
final class PasswordRules
{
    public static function default(): Password
    {
        $rule = Password::min(12)->letters()->mixedCase()->numbers();

        return app()->environment('testing') ? $rule : $rule->uncompromised();
    }
}
