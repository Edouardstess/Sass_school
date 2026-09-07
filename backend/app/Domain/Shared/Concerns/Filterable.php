<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Whitelisted sorting and filtering for index endpoints.
 *
 * Column names come from the query string, so they are matched against an
 * explicit allow-list rather than interpolated — an unlisted column is
 * ignored, which closes the door on ORDER BY injection and on sorting by a
 * column the caller should not even know exists.
 */
trait Filterable
{
    /**
     * Apply `?sort=field` / `?sort=-field`, falling back to a stable default.
     *
     * @param  list<string>  $allowed
     */
    public function scopeApplySort(Builder $query, ?string $sort, array $allowed, string $default = '-created_at'): Builder
    {
        $sort = $sort ?: $default;
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-+');

        if (! in_array($column, $allowed, true)) {
            $column = ltrim($default, '-+');
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        // A secondary key on the primary key keeps pagination deterministic
        // when many rows share the same sort value.
        return $query->orderBy($query->qualifyColumn($column), $direction)
            ->orderBy($query->qualifyColumn($this->getKeyName()), 'asc');
    }

    /**
     * Case- and accent-insensitive `ILIKE` search across the given columns.
     *
     * The term is escaped for LIKE metacharacters so a user typing `%` does
     * not turn the query into a full scan of the tenant.
     *
     * @param  list<string>  $columns
     */
    public function scopeApplySearch(Builder $query, ?string $term, array $columns): Builder
    {
        $term = trim((string) $term);

        if ($term === '' || $columns === []) {
            return $query;
        }

        $escaped = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(function (Builder $inner) use ($columns, $escaped): void {
            foreach ($columns as $column) {
                $inner->orWhereRaw(
                    sprintf('unaccent(%s) ILIKE unaccent(?)', $inner->qualifyColumn($column)),
                    [$escaped]
                );
            }
        });
    }
}
