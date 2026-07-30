<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Support\WorkspaceMemberRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RemoveTenantMemberAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private WorkspaceMemberRoles $memberRoles,
    ) {}

    public function handle(TenantMembership $membership, User $removedBy): void
    {
        DB::transaction(function () use ($membership, $removedBy): void {
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

            if ($this->memberRoles->isLastActiveOwner($member, $tenant)) {
                throw ValidationException::withMessages([
                    'membership' => __('The workspace must keep at least one owner.'),
                ]);
            }

            $role = $this->memberRoles->roleFor($member, $tenant);

            $this->logAuditActivity->handle(
                AuditEvent::MemberRemoved,
                $locked,
                [
                    'user_id' => $member->id,
                    'email' => $member->email,
                    'role' => $role,
                ],
                $removedBy,
            );

            $this->memberRoles->clearRoles($member, $tenant);
            $locked->delete();
        });
    }
}
