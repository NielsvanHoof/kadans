<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Support\WorkspaceMemberRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AcceptTenantInvitationAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private WorkspaceMemberRoles $memberRoles,
    ) {}

    public function handle(TenantInvitation $invitation, User $user): TenantMembership
    {
        return DB::transaction(function () use ($invitation, $user): TenantMembership {
            $lockQuery = TenantInvitation::queryWithoutTenantScope()
                ->whereKey($invitation->getKey());

            // lockForUpdate lives on the base builder; call it there for strict-rules.
            $lockQuery->getQuery()->lockForUpdate();

            $locked = $lockQuery->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation is no longer valid.'),
                ]);
            }

            if (mb_strtolower($user->email) !== mb_strtolower($locked->email)) {
                throw ValidationException::withMessages([
                    'invitation' => __('Sign in with :email to accept this invitation.', [
                        'email' => $locked->email,
                    ]),
                ]);
            }

            $tenant = $locked->tenant;

            if (! $tenant instanceof Tenant) {
                $locked->loadMissing('tenant');
                $tenant = $locked->tenant;
            }

            $this->memberRoles->lockMembershipPair($tenant->id, $user->id);

            $membership = TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->first();

            if ($membership instanceof TenantMembership) {
                if ($membership->status === TenantMembershipStatus::Active) {
                    throw ValidationException::withMessages([
                        'invitation' => __('You are already a member of this workspace.'),
                    ]);
                }

                $membership->forceFill([
                    'status' => TenantMembershipStatus::Active,
                    'joined_at' => now(),
                    'invited_at' => $membership->invited_at ?? $locked->created_at,
                ])->saveOrFail();
            } else {
                $membership = TenantMembership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => TenantMembershipStatus::Active, 'invited_at' => $locked->created_at, 'joined_at' => now()]);
            }

            $this->memberRoles->syncRole($user, $tenant, $locked->role);

            $locked->forceFill(['accepted_at' => now()])->saveOrFail();

            $this->logAuditActivity->handle(
                AuditEvent::MemberInvitationAccepted,
                $membership,
                [
                    'email' => $locked->email,
                    'role' => $locked->role,
                    'invitation_id' => $locked->id,
                ],
                $user,
            );

            return $membership->fresh(['user', 'tenant']) ?? $membership;
        });
    }
}
