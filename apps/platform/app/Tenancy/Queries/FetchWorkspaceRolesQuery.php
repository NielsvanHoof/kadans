<?php

declare(strict_types=1);

namespace App\Tenancy\Queries;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Data\WorkspacePermissionData;
use App\Tenancy\Data\WorkspacePermissionGroupData;
use App\Tenancy\Data\WorkspaceRoleData;
use App\Tenancy\Data\WorkspaceRolesPageData;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

use function array_key_exists;
use function in_array;

/**
 * Assembles the workspace roles Inertia page payload.
 *
 * Reads the grants that are actually persisted for this firm rather than
 * re-printing PermissionRegistry defaults. Authorization goes through Spatie's
 * can(), which reads the same pivot rows, so anything this page shows is what
 * the firm will really experience — and seeding drift stays visible instead of
 * being masked by a page that only echoes the enum.
 */
final readonly class FetchWorkspaceRolesQuery
{
    public function __construct(private PermissionRegistry $registry) {}

    public function handle(Tenant $tenant, bool $canManageRoles): WorkspaceRolesPageData
    {
        $roles = $this->roles($tenant);

        return new WorkspaceRolesPageData(
            roles: $this->roleColumns($roles, $tenant),
            permissionGroups: $this->permissionGroups($roles),
            canManageRoles: $canManageRoles,
        );
    }

    /**
     * This firm's roles, presets first in Role::cases() order.
     *
     * Scoped to the tenant so a Spatie global role (tenant_id null) can never
     * surface here as though the firm owned it.
     *
     * @return Collection<int, TenantRole>
     */
    private function roles(Tenant $tenant): Collection
    {
        $roles = TenantRole::query()
            ->with('permissions')
            ->where('tenant_id', $tenant->id)
            ->where('guard_name', 'web')
            ->get();

        $presetOrder = Role::values();

        return $roles->sortBy([
            static fn (TenantRole $role): int => $role->is_system ? 0 : 1,
            static fn (TenantRole $role): int|string => in_array($role->name, $presetOrder, true)
                ? (int) array_search($role->name, $presetOrder, true)
                : $role->name,
        ])->values();
    }

    /**
     * @param  Collection<int, TenantRole>  $roles
     * @return list<WorkspaceRoleData>
     */
    private function roleColumns(Collection $roles, Tenant $tenant): array
    {
        $memberCounts = $this->activeMemberCountsByRoleName($tenant);

        /** @var list<WorkspaceRoleData> $columns */
        $columns = [];

        foreach ($roles as $role) {
            $columns[] = new WorkspaceRoleData(
                id: $role->id,
                value: $role->name,
                label: $role->displayLabel(),
                isSystem: $role->is_system,
                memberCount: $memberCounts[$role->name] ?? 0,
            );
        }

        return $columns;
    }

    /**
     * Permissions bucketed for display, each carrying the roles that hold it.
     *
     * The registry drives the rows, so a stale permission row left in the
     * database is simply not shown: it has no label, description, or group.
     *
     * @param  Collection<int, TenantRole>  $roles
     * @return list<WorkspacePermissionGroupData>
     */
    private function permissionGroups(Collection $roles): array
    {
        $roleNamesByPermission = $this->roleNamesByPermission($roles);

        /** @var list<WorkspacePermissionGroupData> $groups */
        $groups = [];

        foreach ($this->registry->grouped() as $groupValue => $permissions) {
            $group = PermissionGroup::tryFrom($groupValue);

            if (! $group instanceof PermissionGroup) {
                continue;
            }

            $groups[] = new WorkspacePermissionGroupData(
                value: $group->value,
                label: $group->label(),
                permissions: array_map(
                    static fn (TenantPermission $permission): WorkspacePermissionData => new WorkspacePermissionData(
                        value: $permission->value,
                        label: $permission->label(),
                        description: $permission->description(),
                        grantedTo: $roleNamesByPermission[$permission->value] ?? [],
                    ),
                    $permissions,
                ),
            );
        }

        return $groups;
    }

    /**
     * Invert the eager-loaded pivot: permission name => role names holding it.
     *
     * @param  Collection<int, TenantRole>  $roles
     * @return array<string, list<string>>
     */
    private function roleNamesByPermission(Collection $roles): array
    {
        /** @var array<string, list<string>> $roleNamesByPermission */
        $roleNamesByPermission = [];

        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissionName = $permission->name;

                if (! array_key_exists($permissionName, $roleNamesByPermission)) {
                    $roleNamesByPermission[$permissionName] = [];
                }

                $roleNamesByPermission[$permissionName][] = $role->name;
            }
        }

        return $roleNamesByPermission;
    }

    /**
     * Active members per role name, so the page can show which roles are in use.
     *
     * Counted in PHP from one join: firms hold tens of members, and this keeps
     * the query free of raw SQL. Spatie's role() scope is avoided deliberately —
     * see the note in WorkspaceMemberRoles::activeOwnerCount().
     *
     * @return array<string, int>
     */
    private function activeMemberCountsByRoleName(Tenant $tenant): array
    {
        $teamForeignKey = Config::string('permission.column_names.team_foreign_key');
        $rolesTable = Config::string('permission.table_names.roles');
        $modelHasRolesTable = Config::string('permission.table_names.model_has_roles');
        $modelKey = Config::string('permission.column_names.model_morph_key');

        $rows = DB::table('tenant_memberships')
            ->join($modelHasRolesTable, function ($join) use ($tenant, $teamForeignKey, $modelHasRolesTable, $modelKey): void {
                $join->on('tenant_memberships.user_id', '=', "{$modelHasRolesTable}.{$modelKey}")
                    ->where("{$modelHasRolesTable}.model_type", (new User)->getMorphClass())
                    ->where("{$modelHasRolesTable}.{$teamForeignKey}", $tenant->id);
            })
            ->join($rolesTable, "{$rolesTable}.id", '=', "{$modelHasRolesTable}.role_id")
            ->where('tenant_memberships.tenant_id', $tenant->id)
            ->where('tenant_memberships.status', TenantMembershipStatus::Active->value)
            ->distinct()
            ->get([
                "{$rolesTable}.name as role_name",
                'tenant_memberships.user_id as user_id',
            ]);

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            $roleName = (string) $row->role_name;
            $counts[$roleName] = ($counts[$roleName] ?? 0) + 1;
        }

        return $counts;
    }
}
