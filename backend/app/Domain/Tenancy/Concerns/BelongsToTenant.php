<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\School\Models\School;
use App\Domain\Shared\Exceptions\TenantMismatchException;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model that stores tenant-owned data.
 *
 * Provides three guarantees:
 *   1. reads are filtered to the active school (global scope);
 *   2. writes are stamped with the active school automatically;
 *   3. a write that names a *different* school is rejected outright, so a
 *      mass-assignment attempt cannot move a record between tenants.
 *
 * @property string|null $school_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            $context = app(TenantContext::class);

            if ($model->getAttribute($model->getTenantColumn()) === null && $context->has()) {
                $model->setAttribute($model->getTenantColumn(), $context->id());
            }

            $model->assertTenantIntegrity();
        });

        // Reassigning a record to another school is never a legitimate update.
        static::updating(function (self $model): void {
            $column = $model->getTenantColumn();

            if ($model->isDirty($column) && $model->getOriginal($column) !== null) {
                throw new TenantMismatchException(
                    'The owning school of an existing record cannot be changed.'
                );
            }

            $model->assertTenantIntegrity();
        });
    }

    public function getTenantColumn(): string
    {
        return 'school_id';
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, $this->getTenantColumn());
    }

    /**
     * Escape hatch for platform-level queries. Named verbosely on purpose:
     * every call site should be obvious in review and in a grep.
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    /** Query a specific tenant regardless of the ambient context. */
    public function scopeForTenant(Builder $query, string $schoolId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn($this->getTenantColumn()), $schoolId);
    }

    private function assertTenantIntegrity(): void
    {
        $context = app(TenantContext::class);
        $schoolId = $this->getAttribute($this->getTenantColumn());

        if ($schoolId === null || ! $context->scopeIsEnabled()) {
            return;
        }

        if ($schoolId !== $context->id()) {
            throw new TenantMismatchException(sprintf(
                'Refusing to persist a %s owned by school %s while school %s is active.',
                class_basename($this),
                $schoolId,
                (string) $context->id(),
            ));
        }
    }
}
