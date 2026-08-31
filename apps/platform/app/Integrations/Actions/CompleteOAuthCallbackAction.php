<?php

declare(strict_types=1);

namespace App\Integrations\Actions;

use App\Accounts\Models\User;
use App\Integrations\Data\OAuthCallbackResult;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Models\OAuthConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as SocialiteUser;

use function is_string;
use function mb_strtolower;

/**
 * Match or link a Socialite identity to an existing Qompose user.
 *
 * Never creates a User — SSO is login/link only. Invitation accept still
 * creates accounts. Tokens are stored encrypted for later API / calendar use.
 */
final readonly class CompleteOAuthCallbackAction
{
    public function handle(
        OAuthProvider $provider,
        SocialiteUser $socialiteUser,
        ?User $authenticatedUser = null,
    ): OAuthCallbackResult {
        $providerUserId = (string) $socialiteUser->getId();
        $email = $this->normalizeEmail($socialiteUser->getEmail());

        if ($authenticatedUser instanceof User) {
            return $this->linkForAuthenticatedUser(
                $provider,
                $socialiteUser,
                $providerUserId,
                $email,
                $authenticatedUser,
            );
        }

        return $this->matchForGuest($provider, $socialiteUser, $providerUserId, $email);
    }

    private function matchForGuest(
        OAuthProvider $provider,
        SocialiteUser $socialiteUser,
        string $providerUserId,
        ?string $email,
    ): OAuthCallbackResult {
        $existing = OAuthConnection::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existing instanceof OAuthConnection) {
            $user = $existing->user;
            $this->refreshTokens($existing, $socialiteUser, $email);

            return OAuthCallbackResult::success($user, $existing->fresh() ?? $existing);
        }

        if ($email === null) {
            return OAuthCallbackResult::failure(
                __('No Qompose account for this email.'),
            );
        }

        $userQuery = User::query();
        $userQuery->getQuery()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNotNull('email_verified_at');
        $user = $userQuery->first();

        if (! $user instanceof User) {
            return OAuthCallbackResult::failure(
                __('No Qompose account for this email.'),
            );
        }

        // User already linked to a different account for this provider — refuse
        // to swap silently on a guest login; they must unlink from settings first.
        $alreadyLinked = OAuthConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->toBase()
            ->exists();

        if ($alreadyLinked) {
            return OAuthCallbackResult::failure(
                __('This :provider account does not match the one linked to your Qompose account.', [
                    'provider' => $provider->label(),
                ]),
            );
        }

        $connection = $this->createConnection($provider, $user, $socialiteUser, $providerUserId, $email);

        return OAuthCallbackResult::success($user, $connection);
    }

    private function linkForAuthenticatedUser(
        OAuthProvider $provider,
        SocialiteUser $socialiteUser,
        string $providerUserId,
        ?string $email,
        User $authenticatedUser,
    ): OAuthCallbackResult {
        $ownedByOther = OAuthConnection::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->where('user_id', '!=', $authenticatedUser->id)
            ->toBase()
            ->exists();

        if ($ownedByOther) {
            return OAuthCallbackResult::failure(
                __('This :provider account is already linked to another Qompose user.', [
                    'provider' => $provider->label(),
                ]),
            );
        }

        $existingForUser = OAuthConnection::query()
            ->where('user_id', $authenticatedUser->id)
            ->where('provider', $provider)
            ->first();

        if ($existingForUser instanceof OAuthConnection) {
            // Same oid → refresh tokens; different oid → refuse overwrite.
            if ($existingForUser->provider_user_id !== $providerUserId) {
                return OAuthCallbackResult::failure(
                    __('Disconnect your current :provider account before linking a different one.', [
                        'provider' => $provider->label(),
                    ]),
                );
            }

            $this->refreshTokens($existingForUser, $socialiteUser, $email);

            return OAuthCallbackResult::success(
                $authenticatedUser,
                $existingForUser->fresh() ?? $existingForUser,
            );
        }

        $connection = $this->createConnection(
            $provider,
            $authenticatedUser,
            $socialiteUser,
            $providerUserId,
            $email,
        );

        return OAuthCallbackResult::success($authenticatedUser, $connection);
    }

    private function createConnection(
        OAuthProvider $provider,
        User $user,
        SocialiteUser $socialiteUser,
        string $providerUserId,
        ?string $email,
    ): OAuthConnection {
        return DB::transaction(fn (): OAuthConnection => OAuthConnection::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'access_token' => $socialiteUser->token,
            'refresh_token' => $socialiteUser->refreshToken,
            'token_expires_at' => $this->expiresAt($socialiteUser),
            'scopes' => $this->scopes($socialiteUser, $provider),
        ]));
    }

    private function refreshTokens(
        OAuthConnection $connection,
        SocialiteUser $socialiteUser,
        ?string $email,
    ): void {
        $connection->forceFill([
            'email' => $email ?? $connection->email,
            'access_token' => $socialiteUser->token,
            'refresh_token' => $socialiteUser->refreshToken !== ''
                ? $socialiteUser->refreshToken
                : $connection->refresh_token,
            'token_expires_at' => $this->expiresAt($socialiteUser) ?? $connection->token_expires_at,
            'scopes' => $this->scopes($socialiteUser, $connection->provider),
        ])->save();
    }

    private function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email) || mb_trim($email) === '') {
            return null;
        }

        return mb_strtolower(mb_trim($email));
    }

    private function expiresAt(SocialiteUser $socialiteUser): ?CarbonImmutable
    {
        $expiresIn = $socialiteUser->expiresIn;

        if ($expiresIn <= 0) {
            return null;
        }

        return Date::now()->addSeconds($expiresIn)->toImmutable();
    }

    /**
     * @return list<string>
     */
    private function scopes(SocialiteUser $socialiteUser, OAuthProvider $provider): array
    {
        $approved = $socialiteUser->approvedScopes;

        if ($approved !== []) {
            /** @var list<string> $approved */
            return array_values(array_map(static fn (mixed $scope): string => (string) $scope, $approved));
        }

        return $provider->scopes();
    }
}
