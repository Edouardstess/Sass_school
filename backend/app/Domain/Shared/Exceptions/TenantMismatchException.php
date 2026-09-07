<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * A record from another tenant reached code that assumed the active tenant.
 *
 * This should be unreachable — the global scope, the policies and the route
 * bindings each prevent it independently. If it ever fires it means one of
 * those layers has a hole, so it is deliberately loud rather than swallowed.
 */
final class TenantMismatchException extends RuntimeException {}
