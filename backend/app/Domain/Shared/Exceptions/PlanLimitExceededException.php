<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * The tenant's subscription plan does not allow this action (a ceiling was
 * reached, or a feature is not in the plan). Rendered as HTTP 402 so the
 * frontend can show an upgrade path rather than a generic error.
 */
final class PlanLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $feature,
        public readonly ?int $limit,
        public readonly ?int $current,
        string $message,
    ) {
        parent::__construct($message);
    }
}
