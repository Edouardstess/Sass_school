<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and caches a user's effective permissions.
 *
 * Effective set = (union of the permissions of every role held)
 *                 + individually granted permissions
 *                 − individually revoked permissions
 *
 * The cache key embeds the user's `permissions_version`, which is incremented
 * whenever a role or grant changes. That makes a revocation effective on the
 * very next request instead of after a TTL expires — the failure mode that
 * matters here is a fired employee keeping access, not a cache miss.
 */
final class PermissionRegistry
{
    private const CACHE_TTL_SECONDS = 900;

    /**
     * Per-request memoisation, on top of the shared cache. A single request
     * asks "can this user…" many times across policies and resources.
     *
     * @var array<string, list<string>>
     */
    private array $memo = [];

    /** @return list<string> */
    public function forUser(User $user): array
    {
        $key = $this->cacheKey($user);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        /** @var list<string> $permissions */
        $permissions = Cache::remember(
            $key,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->resolve($user),
        );

        return $this->memo[$key] = $permissions;
    }

    public function forget(User $user): void
    {
        unset($this->memo[$this->cacheKey($user)]);
        Cache::forget($this->cacheKey($user));
    }

    /** Drop every memoised entry — used between tests and in long-lived workers. */
    public function flushLocal(): void
    {
        $this->memo = [];
    }

    /**
     * Resolve from the database in two queries rather than N.
     *
     * @return list<string>
     */
    private function resolve(User $user): array
    {
        $fromRoles = DB::table('permissions')
            ->join('role_permission', 'permissions.id', '=', 'role_permission.permission_id')
            ->join('user_role', 'role_permission.role_id', '=', 'user_role.role_id')
            ->where('user_role.user_id', $user->id)
            ->pluck('permissions.name')
            ->all();

        $overrides = DB::table('permissions')
            ->join('user_permission', 'permissions.id', '=', 'user_permission.permission_id')
            ->where('user_permission.user_id', $user->id)
            ->get(['permissions.name', 'user_permission.granted']);

        $granted = [];
        $revoked = [];

        foreach ($overrides as $override) {
            if ($override->granted) {
                $granted[] = $override->name;
            } else {
                $revoked[] = $override->name;
            }
        }

        $effective = array_diff(
            array_unique([...$fromRoles, ...$granted]),
            $revoked,
        );

        // sort() reindexes in place, so the result is already a list.
        sort($effective);

        return $effective;
    }

    private function cacheKey(User $user): string
    {
        return "permissions:{$user->id}:v{$user->permissions_version}";
    }

    /**
     * Expand a role template's wildcard entries into concrete permission
     * names, e.g. `students.*` → every permission in the `students` group,
     * and `*` → everything. Used by the seeder and by role management.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public function expandPatterns(array $patterns): array
    {
        $catalogue = collect(config('schoolflow.permissions'))->flatten()->all();

        if (in_array('*', $patterns, true)) {
            return array_values($catalogue);
        }

        $expanded = [];

        foreach ($patterns as $pattern) {
            if (! str_contains($pattern, '*')) {
                $expanded[] = $pattern;

                continue;
            }

            $prefix = rtrim($pattern, '*');

            foreach ($catalogue as $permission) {
                if (str_starts_with($permission, $prefix)) {
                    $expanded[] = $permission;
                }
            }
        }

        return array_unique($expanded);
    }

    /** Every permission name the product defines, from the config catalogue. */
    public function catalogue(): array
    {
        return collect(config('schoolflow.permissions'))
            ->flatMap(fn (array $names, string $group) => array_map(
                fn (string $name): array => ['name' => $name, 'group' => $group],
                $names,
            ))
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function existingNames(): array
    {
        return Permission::query()->pluck('name')->all();
    }
}
