<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Domain\School\Models\School;
use App\Domain\Tenancy\TenantContext;
use Closure;

/**
 * Re-establishes the tenant context inside a queue worker.
 *
 * A worker has no HTTP request, so nothing sets the active school for it.
 * Every tenant-aware job therefore serialises its `school_id` and re-enters
 * the context here. Without this, a job would run with the global scope
 * disabled and could read — or worse, write — across tenants.
 */
trait RunsInTenant
{
    /**
     * @template T
     *
     * @param  Closure(School): T  $callback
     * @return T|null null when the school has since been deleted
     */
    protected function inTenant(string $schoolId, Closure $callback): mixed
    {
        $school = School::query()->find($schoolId);

        if ($school === null) {
            // The tenant was deleted between enqueue and execution. Doing
            // nothing is correct; failing would retry forever.
            return null;
        }

        return app(TenantContext::class)->runFor($school, fn () => $callback($school));
    }
}
