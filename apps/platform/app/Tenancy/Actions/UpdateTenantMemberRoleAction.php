<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Support\WorkspaceMemberRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateTenantMemberRoleAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private WorkspaceMemberRoles $memberRoles,
    ) {}

    public function handle(TenantMembership $membership, User $updatedBy, string $role): TenantMembership
    {
        return DB::transaction(function () use ($membership, $updatedBy, $role): TenantMembership {
            $membershipQuery = TenantMembership::query()->whereKey($membership->getKey());
            $membershipQuery->getQuery()->lockForUpdate();
            $locked = $membershipQuery->firstOrFail();
            $locked->loadMissing(['user', 'tenant']);

            $tenant = $this->memberRoles->requireTenant($locked);
            $member = $locked->user;

            if (! $member instanceof User) {
                throw ValidationException::withMessages([
                    'membership' => __('This membership is invalid.'),
                ]);
            }

            if ($locked->status !== TenantMembershipStatus::Active) {
                throw ValidationException::withMessages([
                    'role' => __('Only active members can change roles.'),
                ]);
            }

            if (! $this->memberRoles->actorCanAssignRole($updatedBy, $tenant, $role)) {
                throw ValidationException::withMessages([
                    'role' => __('Only an owner can assign the owner role.'),
                ]);
            }

            $previousRole = $this->memberRoles->roleFor($member, $tenant);

            if ($previousRole === Role::Owner->value && $role !== Role::Owner->value
                && $this->memberRoles->isLastActiveOwner($member, $tenant)) {
                throw ValidationException::withMessages([
                    'role' => __('The workspace must keep at least one owner.'),
                ]);
            }

            if ($previousRole === $role) {
                return $locked;
            }

            $this->memberRoles->syncRole($member, $tenant, $role);

            $this->logAuditActivity->handle(
                AuditEvent::MemberRoleChanged,
                $locked,
                [
                    'user_id' => $member->id,
                    'email' => $member->email,
                    'old_role' => $previousRole,
                    'new_role' => $role,
                ],
                $updatedBy,
            );

            return $locked->fresh(['user', 'tenant']) ?? $locked;
        });
    }
}
