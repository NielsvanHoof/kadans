<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Members;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Support\Members\StaffOptionsCache;
use App\Tenancy\Support\Members\WorkspaceMemberRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SuspendTenantMemberAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private WorkspaceMemberRoles $memberRoles,
    ) {}

    public function handle(TenantMembership $membership, User $suspendedBy): TenantMembership
    {
        $tenantId = $membership->tenant_id;

        $result = DB::transaction(function () use ($membership, $suspendedBy): TenantMembership {
            $this->memberRoles->lockOwnerMutations($membership->tenant_id);

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

            if ($locked->status === TenantMembershipStatus::Suspended) {
                return $locked;
            }

            if ($this->memberRoles->isLastActiveOwner($member, $tenant)) {
                throw ValidationException::withMessages([
                    'membership' => __('The workspace must keep at least one owner.'),
                ]);
            }

            $locked->forceFill(['status' => TenantMembershipStatus::Suspended])->saveOrFail();

            $this->logAuditActivity->handle(
                AuditEvent::MemberSuspended,
                $locked,
                [
                    'user_id' => $member->id,
                    'email' => $member->email,
                    'role' => $this->memberRoles->roleFor($member, $tenant),
                ],
                $suspendedBy,
            );

            return $locked->fresh(['user', 'tenant']) ?? $locked;
        });

        StaffOptionsCache::forget($tenantId);

        return $result;
    }
}
