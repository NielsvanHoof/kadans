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
use App\Tenancy\Support\WorkspaceMemberRoles;
use Illuminate\Database\Eloquent\Collection;

use function is_string;

/**
 * Assembles the workspace members Inertia page payload.
 *
 * Kept as one page query (like FetchWorkspaceDashboardQuery): private methods
 * own each section; role resolution stays on WorkspaceMemberRoles.
 */
final class FetchWorkspaceMembersQuery
{
    public function __construct(private readonly WorkspaceMemberRoles $memberRoles) {}

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
            roleOptions: $this->assignableRoleOptions($viewerIsOwner),
            canAssignOwner: $viewerIsOwner,
        );
    }

    /**
     * @return Collection<int, TenantMembership>
     */
    private function memberships(Tenant $tenant): Collection
    {
        $membershipsQuery = TenantMembership::query()
            ->with('user')
            ->where('tenant_id', $tenant->id);

        // Prefer the base query builder for whereIn/orderBy to satisfy strict-rules.
        $membershipsQuery->getQuery()
            ->whereIn('status', [
                TenantMembershipStatus::Active->value,
                TenantMembershipStatus::Suspended->value,
            ])
            ->orderBy('joined_at');

        return $membershipsQuery->get();
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
            $role = is_string($roleName) ? Role::tryFrom($roleName) : null;

            $members[] = new WorkspaceMemberData(
                id: $membership->id,
                userId: $user->id,
                name: $user->name,
                email: $user->email,
                status: $membership->status->value,
                role: $role?->value,
                roleLabel: $role?->label(),
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
        $invitationsQuery->getQuery()->orderByDesc('created_at');

        /** @var list<WorkspaceInvitationData> $invitations */
        $invitations = [];

        foreach ($invitationsQuery->get() as $invitation) {
            $invitations[] = new WorkspaceInvitationData(
                id: $invitation->id,
                email: $invitation->email,
                role: $invitation->role->value,
                roleLabel: $invitation->role->label(),
                invitedAt: $invitation->created_at?->toIso8601String() ?? now()->toIso8601String(),
                expiresAt: $invitation->expires_at->toIso8601String(),
            );
        }

        return $invitations;
    }

    /**
     * @return list<WorkspaceRoleOptionData>
     */
    private function assignableRoleOptions(bool $viewerIsOwner): array
    {
        /** @var list<WorkspaceRoleOptionData> $roleOptions */
        $roleOptions = [];

        foreach (Role::assignable() as $role) {
            if (! $viewerIsOwner && $role === Role::Owner) {
                continue;
            }

            $roleOptions[] = new WorkspaceRoleOptionData(
                value: $role->value,
                label: $role->label(),
            );
        }

        return $roleOptions;
    }
}
