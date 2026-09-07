<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\School\Models\School;
use App\Domain\Shared\Exceptions\TenantMismatchException;
use Closure;
use RuntimeException;

/**
 * The single source of truth for "which school am I acting inside right now".
 *
 * Registered as a singleton. HTTP requests populate it in the ResolveTenant
 * middleware; queue jobs repopulate it from the tenant id they serialised,
 * because a worker has no request to read it from.
 *
 * Everything that reads or writes tenant data goes through here, so there is
 * exactly one place to audit when reasoning about isolation.
 */
final class TenantContext
{
    private ?School $school = null;

    /**
     * When true, the tenant scope is suspended for the current closure. It is
     * deliberately private and only reachable through `withoutScope()`, so a
     * grep for that method finds every place isolation is intentionally
     * bypassed.
     */
    private bool $scopeDisabled = false;

    /** True when a platform super admin is acting inside a tenant. */
    private bool $impersonating = false;

    public function set(School $school, bool $impersonating = false): void
    {
        $this->school = $school;
        $this->impersonating = $impersonating;
    }

    public function forget(): void
    {
        $this->school = null;
        $this->impersonating = false;
    }

    public function school(): ?School
    {
        return $this->school;
    }

    public function id(): ?string
    {
        return $this->school?->id;
    }

    public function has(): bool
    {
        return $this->school !== null;
    }

    public function isImpersonating(): bool
    {
        return $this->impersonating;
    }

    /**
     * The active tenant id, or a hard failure.
     *
     * Used by code whose correctness depends on a tenant being present — it is
     * better to fail the request than to silently write a row with a null
     * school_id that would then be visible to nobody, or worse, to everybody.
     */
    public function idOrFail(): string
    {
        return $this->id() ?? throw new RuntimeException(
            'No active tenant. This code path requires a school context.'
        );
    }

    public function schoolOrFail(): School
    {
        return $this->school ?? throw new RuntimeException(
            'No active tenant. This code path requires a school context.'
        );
    }

    public function scopeIsEnabled(): bool
    {
        return ! $this->scopeDisabled && $this->has();
    }

    /**
     * Run a callback with tenant scoping suspended.
     *
     * Reserved for platform-level work: cross-tenant analytics, the super-admin
     * dashboard, and the scheduler when it sweeps every school. Application
     * code inside a tenant must never call this.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutScope(Closure $callback): mixed
    {
        $previous = $this->scopeDisabled;
        $this->scopeDisabled = true;

        try {
            return $callback();
        } finally {
            $this->scopeDisabled = $previous;
        }
    }

    /**
     * Run a callback as though a different school were active, then restore.
     *
     * Used by the scheduler, which iterates every tenant, and by platform
     * admins acting on a specific school.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runFor(School $school, Closure $callback, bool $impersonating = false): mixed
    {
        $previousSchool = $this->school;
        $previousImpersonating = $this->impersonating;

        $this->set($school, $impersonating);

        try {
            return $callback();
        } finally {
            $this->school = $previousSchool;
            $this->impersonating = $previousImpersonating;
        }
    }

    /**
     * Assert that a value belongs to the active tenant.
     *
     * The last line of defence, called by services that receive an already
     * resolved model. If it ever throws, an earlier layer has a hole.
     */
    public function assertBelongs(?string $schoolId): void
    {
        if (! $this->has() || $this->scopeDisabled) {
            return;
        }

        if ($schoolId !== null && $schoolId !== $this->id()) {
            throw new TenantMismatchException(
                'Refusing to operate on a record belonging to another school.'
            );
        }
    }
}
