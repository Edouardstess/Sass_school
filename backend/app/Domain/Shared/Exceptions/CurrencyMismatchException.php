<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/** Thrown when arithmetic is attempted between two different currencies. */
final class CurrencyMismatchException extends RuntimeException {}
