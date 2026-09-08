<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Invitations;

use App\Accounts\Actions\Profile\CreateNewUser;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final readonly class RegisterAndAcceptTenantInvitationAction
{
    public function __construct(
        private CreateNewUser $createNewUser,
        private AcceptTenantInvitationAction $acceptTenantInvitation,
    ) {}

    /**
     * Register a new user from an invitation and accept membership atomically.
     * Authentication and the Registered event run only after the transaction commits.
     *
     * @param  array{name: string, email: string, password: string, password_confirmation: string}  $input
     */
    public function handle(TenantInvitation $invitation, array $input): TenantMembership
    {
        // Lock registration email to the invite so accounts cannot be hijacked.
        $input['email'] = $invitation->email;

        [$user, $membership] = DB::transaction(function () use ($invitation, $input): array {
            $user = $this->createNewUser->create($input);

            // Mark verified — they proved inbox access by opening the invite link.
            $user->forceFill(['email_verified_at' => now()])->save();

            $membership = $this->acceptTenantInvitation->handle($invitation, $user);

            return [$user, $membership];
        });

        Event::dispatch(new Registered($user));
        Auth::login($user);

        return $membership;
    }
}
