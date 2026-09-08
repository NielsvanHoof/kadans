<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Tenancy\Enums\Role as RoleEnum;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole as RoleModel;
use App\Tenancy\Support\PermissionRegistry;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

use function getPermissionsTeamId;
use function in_array;
use function setPermissionsTeamId;

/**
 * Reconciles persisted roles and permissions with what the code declares.
 *
 * Roles are seeded per tenant at provision time and never revisited, so any
 * change to the permission set would otherwise only reach newly created firms.
 * This action is idempotent: running it against an unchanged codebase is a
 * no-op, which makes it safe to call from provisioning, the seeder, and
 * deploy-time migrations alike.
 */
final readonly class SyncTenantRolesAction
{
    public function __construct(private PermissionRegistry $registry) {}

    /**
     * Sync the global permission table, then every tenant's roles.
     *
     * @param  bool  $prune  Also delete permission rows the code no longer declares.
     * @return array{permissions_created: int, permissions_pruned: int, tenants_synced: int, roles_created: int}
     */
    public function handle(bool $prune = false): array
    {
        $permissionsCreated = $this->syncPermissionTable();
        $permissionsPruned = $prune ? $this->prunePermissionTable() : 0;

        $tenantsSynced = 0;
        $rolesCreated = 0;

        // Defer the Spatie cache flush until every tenant is done. Forgetting
        // inside syncTenant() rebuilds the global blob once per firm.
        Tenant::query()->chunkById(100, function ($tenants) use (&$tenantsSynced, &$rolesCreated): void {
            foreach ($tenants as $tenant) {
                $rolesCreated += $this->syncTenant($tenant, refreshPermissionCache: false);
                $tenantsSynced++;
            }
        });

        $this->forgetCachedPermissions();

        return [
            'permissions_created' => $permissionsCreated,
            'permissions_pruned' => $permissionsPruned,
            'tenants_synced' => $tenantsSynced,
            'roles_created' => $rolesCreated,
        ];
    }

    /**
     * Reconcile a single tenant's roles against the registry.
     *
     * @param  bool  $refreshPermissionCache  When false, the caller owns the
     *                                        single forgetCachedPermissions() —
     *                                        used by handle() so a platform-wide
     *                                        sync does not rebuild the blob N times.
     * @return int Number of role rows created.
     */
    public function syncTenant(Tenant $tenant, bool $refreshPermissionCache = true): int
    {
        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($tenant->getKey());

            $rolesCreated = 0;

            foreach (RoleEnum::cases() as $roleEnum) {
                $role = RoleModel::query()->where('name', $roleEnum->value)
                    ->where('guard_name', 'web')
                    ->where('tenant_id', $tenant->getKey())
                    ->first();

                if (! $role instanceof RoleModel) {
                    $role = RoleModel::query()->create([
                        'name' => $roleEnum->value,
                        'guard_name' => 'web',
                        'tenant_id' => $tenant->getKey(),
                        'is_system' => true,
                    ]);

                    $rolesCreated++;
                } elseif (! $role->is_system) {
                    // Repairs rows that predate the is_system column.
                    $role->forceFill(['is_system' => true])->save();
                }

                // syncPermissions both grants what is missing and revokes what
                // the role should no longer hold.
                $role->syncPermissions($this->registry->valuesForRole($roleEnum));
            }

            return $rolesCreated;
        } finally {
            setPermissionsTeamId($previousTeamId);

            if ($refreshPermissionCache) {
                $this->forgetCachedPermissions();
            }
        }
    }

    /**
     * @return int Number of permission rows created.
     */
    private function syncPermissionTable(): int
    {
        $this->forgetCachedPermissions();

        $existing = PermissionModel::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        $created = 0;

        foreach ($this->registry->values() as $name) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            PermissionModel::query()->create([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Remove permission rows no longer declared in code. Pivot rows cascade.
     *
     * @return int Number of permission rows deleted.
     */
    private function prunePermissionTable(): int
    {
        $registered = $this->registry->values();

        // The permission table holds a few dozen rows, so filtering in PHP keeps
        // the comparison in one place alongside the registry.
        $stale = PermissionModel::query()
            ->where('guard_name', 'web')
            ->get()
            ->reject(static fn (PermissionModel $permission): bool => in_array(
                $permission->name,
                $registered,
                true,
            ));

        foreach ($stale as $permission) {
            // A permission still referenced by an unexpected constraint is left
            // in place rather than failing the whole sync — but rescue() reports
            // so permission-sync drift is not invisible.
            rescue(static fn (): ?bool => $permission->delete());
        }

        return $stale->count();
    }

    private function forgetCachedPermissions(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
