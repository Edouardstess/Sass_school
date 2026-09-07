<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * A business rule was violated — not a bug, and not a validation error on a
 * single field. The HTTP layer renders these as 409 Conflict with the domain
 * message intact, so the user is told what actually went wrong.
 */
class DomainException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $context */
    public static function make(string $message, array $context = []): static
    {
        return new static($message, $context);
    }
}
