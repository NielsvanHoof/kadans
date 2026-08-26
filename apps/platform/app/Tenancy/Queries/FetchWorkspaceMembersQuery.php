<?php

declare(strict_types=1);

namespace App\Tenancy\Queries;

use App\Accounts\Models\User;
use App\Tenancy\Data\WorkspaceInvitationData;
use App\Tenancy\Data\WorkspaceMemberData;
use App\Tenancy\Data\WorkspaceMembersPageData;
use App\Tenancy\Data\WorkspaceRoleOptionData;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Support\WorkspaceMemberRoles;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

use function is_string;

/**
 * Assembles the workspace members Inertia page payload.
 *
 * Kept as one page query (like FetchWorkspaceDashboardQuery): private methods
 * own each section; role resolution stays on WorkspaceMemberRoles.
 */
final readonly class FetchWorkspaceMembersQuery
{
    /**
     * Same cap as the client picker: large enough for any realistic firm,
     * still a hard bound so this page cannot load every membership row.
     *
     * Viewer-specific (isCurrentUser, canAssignOwner), so the payload
     * stays uncached. Staff dropdowns use StaffOptionsCache instead.
     */
    private const int MEMBERS_PAGE_LIMIT = 500;

    public function __construct(
        private WorkspaceMemberRoles $memberRoles,
        private Request $request,
    ) {}

    public function handle(Tenant $tenant, User $viewer): WorkspaceMembersPageData
    {
        $memberships = $this->memberships($tenant);
        $roleNamesByUserId = $this->memberRoles->roleNamesForUsers(
            $tenant,
            $this->userIds($memberships),
            $viewer,
        );
        $viewerIsOwner = ($roleNamesByUserId[$viewer->id] ?? null) === Role::Owner->value;

        return new WorkspaceMembersPageData(
            members: $this->members($memberships, $viewer, $roleNamesByUserId),
            invitations: $this->pendingInvitations($tenant),
            roleOptions: $this->assignableRoleOptions($tenant, $viewerIsOwner),
            canAssignOwner: $viewerIsOwner,
            roleFilter: $this->filteredRoleName(),
        );
    }

    /**
     * @return Collection<int, TenantMembership>
     */
    private function memberships(Tenant $tenant): Collection
    {
        $roleFilter = $this->filteredRoleName();
        $membershipsQuery = TenantMembership::query()
            ->with('user')
            ->where('tenant_id', $tenant->id);

        // Prefer the base query builder for whereIn/orderBy to satisfy strict-rules.
        $membershipsQuery->getQuery()
            ->whereIn('status', [
                TenantMembershipStatus::Active->value,
                TenantMembershipStatus::Suspended->value,
            ])
            ->oldest('joined_at')
            ->limit(self::MEMBERS_PAGE_LIMIT);

        if (is_string($roleFilter) && $roleFilter !== '') {
            $teamForeignKey = Config::string('permission.column_names.team_foreign_key');
            $rolesTable = Config::string('permission.table_names.roles');
            $modelHasRolesTable = Config::string('permission.table_names.model_has_roles');
            $modelKey = Config::string('permission.column_names.model_morph_key');

            $membershipsQuery->getQuery()->whereExists(function (Builder $query) use ($tenant, $roleFilter, $teamForeignKey, $rolesTable, $modelHasRolesTable, $modelKey): void {
                $query->selectRaw('1')
                    ->from($modelHasRolesTable)
                    ->join($rolesTable, "{$rolesTable}.id", '=', "{$modelHasRolesTable}.role_id")
                    ->whereColumn("{$modelHasRolesTable}.{$modelKey}", 'tenant_memberships.user_id')
                    ->where("{$modelHasRolesTable}.model_type", (new User)->getMorphClass())
                    ->where("{$modelHasRolesTable}.{$teamForeignKey}", $tenant->id)
                    ->where("{$rolesTable}.name", $roleFilter);
            });
        }

        return $membershipsQuery->get();
    }

    private function filteredRoleName(): ?string
    {
        $role = $this->request->query('role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * @param  Collection<int, TenantMembership>  $memberships
     * @return list<int>
     */
    private function userIds(Collection $memberships): array
    {
        /** @var list<int> $userIds */
        $userIds = $memberships
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $userIds;
    }

    /**
     * @param  Collection<int, TenantMembership>  $memberships
     * @param  array<int, string>  $roleNamesByUserId
     * @return list<WorkspaceMemberData>
     */
    private function members(
        Collection $memberships,
        User $viewer,
        array $roleNamesByUserId,
    ): array {
        /** @var list<WorkspaceMemberData> $members */
        $members = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if (! $user instanceof User) {
                continue;
            }

            $roleName = $roleNamesByUserId[$user->id] ?? null;
            $roleLabel = is_string($roleName)
                ? (Role::tryFrom($roleName)?->label() ?? $roleName)
                : null;

            $members[] = new WorkspaceMemberData(
                id: $membership->id,
                userId: $user->id,
                name: $user->name,
                email: $user->email,
                status: $membership->status,
                role: $roleName,
                roleLabel: $roleLabel,
                joinedAt: $membership->joined_at?->toIso8601String(),
                lastAccessedAt: $membership->last_accessed_at?->toIso8601String(),
                isCurrentUser: $user->id === $viewer->id,
            );
        }

        return $members;
    }

    /**
     * @return list<WorkspaceInvitationData>
     */
    private function pendingInvitations(Tenant $tenant): array
    {
        $invitationsQuery = TenantInvitation::query()
            ->where('tenant_id', $tenant->id);

        TenantInvitation::constrainToPending($invitationsQuery);
        $invitationsQuery->getQuery()
            ->latest()
            ->limit(self::MEMBERS_PAGE_LIMIT);

        /** @var list<WorkspaceInvitationData> $invitations */
        $invitations = [];

        foreach ($invitationsQuery->get() as $invitation) {
            $invitations[] = new WorkspaceInvitationData(
                id: $invitation->id,
                email: $invitation->email,
                role: $invitation->role,
                roleLabel: Role::tryFrom($invitation->role)?->label() ?? $invitation->role,
                invitedAt: $invitation->created_at?->toIso8601String() ?? now()->toIso8601String(),
                expiresAt: $invitation->expires_at->toIso8601String(),
            );
        }

        return $invitations;
    }

    /**
     * @return list<WorkspaceRoleOptionData>
     */
    private function assignableRoleOptions(Tenant $tenant, bool $viewerIsOwner): array
    {
        $rolesQuery = TenantRole::query()
            ->where('tenant_id', $tenant->id)
            ->where('guard_name', 'web');

        $rolesQuery->getQuery()
            ->orderByDesc('is_system')
            ->orderBy('name');

        $roles = $rolesQuery->get();

        /** @var list<WorkspaceRoleOptionData> $roleOptions */
        $roleOptions = [];

        foreach ($roles as $role) {
            if (! $viewerIsOwner && $role->name === Role::Owner->value) {
                continue;
            }

            $roleOptions[] = new WorkspaceRoleOptionData(
                value: $role->name,
                label: $role->displayLabel(),
            );
        }

        return $roleOptions;
    }
}
