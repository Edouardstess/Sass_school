<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global query scope that confines every query on a tenant-owned model to the
 * active school.
 *
 * This is the layer that makes cross-tenant reads structurally impossible
 * rather than merely discouraged: a developer who forgets a `where` clause
 * still gets a scoped query, and route-model binding resolves through it, so
 * an ID from another tenant produces a 404 rather than leaking a row.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->scopeIsEnabled()) {
            return;
        }

        // Registered only from BelongsToTenant::boot, so the model always
        // carries that trait — something PHPStan cannot see through Eloquent's
        // generic Scope interface, hence the explicit method check.
        $column = method_exists($model, 'getTenantColumn')
            ? $model->getTenantColumn()
            : 'school_id';

        $builder->where($model->qualifyColumn($column), $context->id());
    }
}
