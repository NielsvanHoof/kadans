<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Accounts\Models\User;
use App\Accounts\Support\DisplayTimezones;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Notifications\WorkspaceMemberInviteNotification;
use App\Tenancy\Support\StaffOptionsCache;
use App\Tenancy\Support\WorkspaceMemberRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function is_string;

final readonly class InviteTenantMemberAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private WorkspaceMemberRoles $memberRoles,
    ) {}

    /**
     * @return array{invitation: TenantInvitation, plain_text_token: string}
     */
    public function handle(Tenant $tenant, User $invitedBy, string $email, string $role): array
    {
        $email = mb_strtolower(mb_trim($email));

        if (! $this->memberRoles->actorCanAssignRole($invitedBy, $tenant, $role)) {
            throw ValidationException::withMessages([
                'role' => __('Only an owner can assign the owner role.'),
            ]);
        }

        $result = DB::transaction(function () use ($tenant, $invitedBy, $email, $role): array {
            $existingMemberQuery = TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->whereHas('user', function (Builder $query) use ($email): void {
                    $query->getQuery()->where('email', $email);
                });

            if ($existingMemberQuery->toBase()->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('This person is already a member.'),
                ]);
            }

            // Replace any pending invite for the same email so resends stay simple.
            $pendingInvites = TenantInvitation::query()
                ->where('tenant_id', $tenant->id)
                ->where('email', $email);

            TenantInvitation::constrainToPending($pendingInvites);

            $pendingInvites->toBase()->update(['revoked_at' => now()]);

            $plainTextToken = Str::random(64);
            $expiresAt = now()->addDays(7);

            $invitation = TenantInvitation::query()->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'role' => $role,
                'token' => TenantInvitation::hashToken($plainTextToken),
                'invited_by' => $invitedBy->id,
                'expires_at' => $expiresAt,
            ]);

            $acceptUrl = URL::route('invitations.show', ['token' => $plainTextToken]);

            $inviteeLocale = User::query()->where('email', $email)->value('locale');
            $locale = is_string($inviteeLocale) && $inviteeLocale !== ''
                ? $inviteeLocale
                : app()->getLocale();

            $inviteeTimezone = User::query()->where('email', $email)->value('timezone');
            $displayTimezone = DisplayTimezones::sanitize(
                is_string($inviteeTimezone) ? $inviteeTimezone : null,
            ) ?? DisplayTimezones::default();

            Notification::route('mail', $email)
                ->notify(
                    new WorkspaceMemberInviteNotification(
                        invitationId: $invitation->id,
                        firmName: $tenant->name,
                        role: $role,
                        acceptUrl: $acceptUrl,
                        expiresAt: $expiresAt,
                        displayTimezone: $displayTimezone,
                    )->locale($locale),
                );

            $this->logAuditActivity->handle(
                AuditEvent::MemberInvited,
                $invitation,
                [
                    'email' => $email,
                    'role' => $role,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                $invitedBy,
            );

            return [
                'invitation' => $invitation,
                'plain_text_token' => $plainTextToken,
            ];
        });

        // After commit so a rolled-back invite does not punch a hole in
        // the cache. Invite itself does not change the active-staff set;
        // accept does. Forget here anyway so a later accept cannot serve
        // a stale dropdown if this action is the only write we see.
        StaffOptionsCache::forget($tenant->id);

        return $result;
    }
}
