<?php

declare(strict_types=1);

namespace App\Tenancy\Support;

use App\Accounts\Models\User;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

use function getPermissionsTeamId;
use function in_array;
use function is_string;
use function setPermissionsTeamId;

/**
 * Shared helpers for workspace member role checks (last owner, assignable roles).
 */
final class WorkspaceMemberRoles
{
    public function roleFor(User $user, Tenant $tenant): ?string
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($tenant->id);

        try {
            // Cached roles belong to whatever team was active when they loaded.
            // Clear them when the team changes so we never return a stale role.
            if ($previousTeamId !== $tenant->id) {
                $user->unsetRelation('roles');
            }

            $roleName = $user->getRoleNames()->first();

            return is_string($roleName) ? $roleName : null;
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * Resolve tenant-scoped role names for many users in one query.
     *
     * Avoids the N+1 from calling roleFor()/getRoleNames() per member.
     *
     * @param  list<int>  $userIds
     * @return array<int, string> user id => role name
     */
    public function roleNamesByUserId(Tenant $tenant, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $teamForeignKey = Config::string('permission.column_names.team_foreign_key');
        $rolesTable = Config::string('permission.table_names.roles');
        $modelHasRolesTable = Config::string('permission.table_names.model_has_roles');
        $modelKey = Config::string('permission.column_names.model_morph_key');

        /** @var array<int, string> $roleNames */
        $roleNames = [];

        $rows = DB::table($modelHasRolesTable)
            ->join($rolesTable, "{$rolesTable}.id", '=', "{$modelHasRolesTable}.role_id")
            ->where("{$modelHasRolesTable}.model_type", (new User)->getMorphClass())
            ->where("{$modelHasRolesTable}.{$teamForeignKey}", $tenant->id)
            ->where(function (Builder $query) use ($rolesTable, $teamForeignKey, $tenant): void {
                // Match Spatie's teams scope: global roles or this tenant's roles.
                $query->whereNull("{$rolesTable}.{$teamForeignKey}")
                    ->orWhere("{$rolesTable}.{$teamForeignKey}", $tenant->id);
            })
            ->whereIn("{$modelHasRolesTable}.{$modelKey}", $userIds)
            ->get([
                "{$modelHasRolesTable}.{$modelKey} as user_id",
                "{$rolesTable}.name as role_name",
            ]);

        foreach ($rows as $row) {
            $roleNames[(int) $row->user_id] = (string) $row->role_name;
        }

        return $roleNames;
    }

    /**
     * Batch-resolve roles for a set of users, reusing any roles already loaded
     * on $viewer (e.g. after authorize()/can()) so we do not re-query that user.
     *
     * @param  list<int>  $userIds
     * @return array<int, string> user id => role name
     */
    public function roleNamesForUsers(Tenant $tenant, array $userIds, User $viewer): array
    {
        /** @var array<int, string> $roleNamesByUserId */
        $roleNamesByUserId = [];

        if (
            in_array($viewer->id, $userIds, true)
            && $viewer->relationLoaded('roles')
        ) {
            $viewerRoleName = $viewer->getRoleNames()->first();

            if (is_string($viewerRoleName)) {
                $roleNamesByUserId[$viewer->id] = $viewerRoleName;
            }
        }

        /** @var list<int> $missingUserIds */
        $missingUserIds = array_values(array_diff($userIds, array_keys($roleNamesByUserId)));

        return $roleNamesByUserId + $this->roleNamesByUserId($tenant, $missingUserIds);
    }

    public function activeOwnerCount(Tenant $tenant): int
    {
        $teamForeignKey = Config::string('permission.column_names.team_foreign_key');
        $rolesTable = Config::string('permission.table_names.roles');
        $modelHasRolesTable = Config::string('permission.table_names.model_has_roles');
        $modelKey = Config::string('permission.column_names.model_morph_key');

        // Count via joins instead of Spatie's role() scope — Attribute/macro
        // scopes trip phpstan-strict-rules (staticMethod.dynamicCall).
        return DB::table('tenant_memberships')
            ->join($modelHasRolesTable, function ($join) use ($tenant, $teamForeignKey, $modelHasRolesTable, $modelKey): void {
                $join->on('tenant_memberships.user_id', '=', "{$modelHasRolesTable}.{$modelKey}")
                    ->where("{$modelHasRolesTable}.model_type", (new User)->getMorphClass())
                    ->where("{$modelHasRolesTable}.{$teamForeignKey}", $tenant->id);
            })
            ->join($rolesTable, "{$rolesTable}.id", '=', "{$modelHasRolesTable}.role_id")
            ->where('tenant_memberships.tenant_id', $tenant->id)
            ->where('tenant_memberships.status', TenantMembershipStatus::Active->value)
            ->where("{$rolesTable}.name", Role::Owner->value)
            ->distinct()
            ->count('tenant_memberships.user_id');
    }

    public function isLastActiveOwner(User $user, Tenant $tenant): bool
    {
        $role = $this->roleFor($user, $tenant);

        if ($role !== Role::Owner->value) {
            return false;
        }

        return $this->activeOwnerCount($tenant) <= 1;
    }

    public function actorCanAssignRole(User $actor, Tenant $tenant, string $role): bool
    {
        if ($role === Role::Owner->value) {
            return $this->roleFor($actor, $tenant) === Role::Owner->value;
        }

        return true;
    }

    /**
     * Assign a single tenant-scoped role, replacing any previous roles for the team.
     */
    public function syncRole(User $user, Tenant $tenant, string $role): void
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($tenant->id);

        try {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            $user->syncRoles([$role]);
        } finally {
            setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }
    }

    /**
     * Remove all Spatie roles for this user within the tenant team.
     */
    public function clearRoles(User $user, Tenant $tenant): void
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($tenant->id);

        try {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            $user->syncRoles([]);
        } finally {
            setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }
    }

    public function membershipBelongsToTenant(TenantMembership $membership, Tenant $tenant): bool
    {
        return $membership->tenant_id === $tenant->id;
    }

    public function requireTenant(TenantMembership $membership): Tenant
    {
        $tenant = $membership->tenant;

        if (! $tenant instanceof Tenant) {
            $membership->loadMissing('tenant');
            $tenant = $membership->tenant;
        }

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Membership is missing its tenant.');

        return $tenant;
    }

    /**
     * Ensure no concurrent accept creates a duplicate membership.
     */
    public function lockMembershipPair(int $tenantId, int $userId): void
    {
        DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Serialize last-owner checks across concurrent membership mutations.
     *
     * Call inside DB::transaction() before locking the target membership row.
     * A lock on that row alone does not serialize two mutations on different
     * owners (remove A + demote B): both count 2 under READ COMMITTED and both
     * commit, leaving zero owners. The tenant row is the shared key.
     */
    public function lockOwnerMutations(int $tenantId): void
    {
        $row = DB::table('tenants')
            ->where('id', $tenantId)
            ->lockForUpdate()
            ->first(['id']);

        throw_unless($row !== null, RuntimeException::class, 'Tenant not found.');
    }
}
