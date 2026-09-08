<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the permission catalogue and the system role templates from
 * config/schoolflow.php.
 *
 * Idempotent: it is safe to re-run after adding a permission to the config,
 * and re-running is in fact the deployment step for introducing one.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(PermissionRegistry::class);

        DB::transaction(function () use ($registry): void {
            $this->seedPermissions($registry);
            $this->seedRoles($registry);
        });
    }

    private function seedPermissions(PermissionRegistry $registry): void
    {
        foreach ($registry->catalogue() as $entry) {
            Permission::query()->updateOrCreate(
                ['name' => $entry['name']],
                ['group' => $entry['group']],
            );
        }

        // Permissions removed from the config are pruned, so a decommissioned
        // verb cannot linger on a role and keep granting access.
        $current = array_column($registry->catalogue(), 'name');
        Permission::query()->whereNotIn('name', $current)->delete();
    }

    private function seedRoles(PermissionRegistry $registry): void
    {
        $permissionIds = Permission::query()->pluck('id', 'name');

        foreach (config('schoolflow.roles') as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['school_id' => null, 'name' => $name],
                [
                    'label' => $definition['label'],
                    'description' => $definition['description'] ?? null,
                    'is_platform_role' => (bool) ($definition['platform'] ?? false),
                    'is_system' => true,
                ],
            );

            $names = $registry->expandPatterns($definition['permissions']);

            // sync() rather than attach(): removing a permission from the
            // template must actually remove it from the role.
            $role->permissions()->sync(
                collect($names)
                    ->map(fn (string $permission) => $permissionIds[$permission] ?? null)
                    ->filter()
                    ->all()
            );
        }
    }
}
