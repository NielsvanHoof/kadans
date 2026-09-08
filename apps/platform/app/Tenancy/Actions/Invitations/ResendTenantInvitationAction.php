<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Invitations;

use App\Accounts\Models\User;
use App\Accounts\Support\Localization\DisplayTimezones;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Notifications\Members\WorkspaceMemberInviteNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function is_string;

final class ResendTenantInvitationAction
{
    /**
     * Rotate the token and resend the invite email.
     *
     * @return array{invitation: TenantInvitation, plain_text_token: string}
     */
    public function handle(TenantInvitation $invitation): array
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation is no longer valid.'),
            ]);
        }

        $tenant = $invitation->tenant;

        if (! $tenant instanceof Tenant) {
            $invitation->loadMissing('tenant');
            $tenant = $invitation->tenant;
        }

        $plainTextToken = Str::random(64);
        $expiresAt = now()->addDays(7);

        $invitation->forceFill([
            'token' => TenantInvitation::hashToken($plainTextToken),
            'expires_at' => $expiresAt,
        ])->saveOrFail();

        $acceptUrl = URL::route('invitations.show', ['token' => $plainTextToken]);

        $inviteeLocale = User::query()->where('email', $invitation->email)->value('locale');
        $locale = is_string($inviteeLocale) && $inviteeLocale !== ''
            ? $inviteeLocale
            : app()->getLocale();

        $inviteeTimezone = User::query()->where('email', $invitation->email)->value('timezone');
        $displayTimezone = DisplayTimezones::sanitize(
            is_string($inviteeTimezone) ? $inviteeTimezone : null,
        ) ?? DisplayTimezones::default();

        Notification::route('mail', $invitation->email)
            ->notify(
                new WorkspaceMemberInviteNotification(
                    invitationId: $invitation->id,
                    firmName: $tenant->name,
                    role: $invitation->role,
                    acceptUrl: $acceptUrl,
                    expiresAt: $expiresAt,
                    displayTimezone: $displayTimezone,
                )->locale($locale),
            );

        return [
            'invitation' => $invitation->fresh() ?? $invitation,
            'plain_text_token' => $plainTextToken,
        ];
    }
}
