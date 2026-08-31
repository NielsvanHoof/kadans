<?php

declare(strict_types=1);

namespace App\Integrations\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Accounts\Models\User;
use App\Integrations\Actions\CompleteOAuthCallbackAction;
use App\Integrations\Actions\UnlinkOAuthConnectionAction;
use App\Integrations\Enums\OAuthProvider;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-user OAuth redirect / callback / unlink.
 *
 * Session + 2FA stay with Fortify; this controller only matches identities.
 */
final class OAuthController extends Controller
{
    use ResolvesCurrentUser;

    public function redirect(Request $request, string $provider): RedirectResponse|Response
    {
        $oauthProvider = $this->resolveProvider($provider);

        abort_unless($oauthProvider->isConfigured(), Response::HTTP_NOT_FOUND);

        // Remember whether this flow started from settings (link) vs login.
        if ($request->user() instanceof User) {
            $request->session()->put('oauth.intent', 'link');
        } else {
            $request->session()->forget('oauth.intent');
        }

        $driver = Socialite::driver($oauthProvider->value);

        if ($driver instanceof AbstractProvider) {
            $driver = $driver->scopes($oauthProvider->scopes());

            $parameters = $oauthProvider->redirectParameters();

            if ($parameters !== []) {
                $driver = $driver->with($parameters);
            }
        }

        $redirect = $driver->redirect();

        if ($request->headers->has('X-Inertia')) {
            return Inertia::location($redirect->getTargetUrl());
        }

        return $redirect;
    }

    public function callback(
        Request $request,
        string $provider,
        CompleteOAuthCallbackAction $completeOAuthCallback,
    ): RedirectResponse {
        $oauthProvider = $this->resolveProvider($provider);

        abort_unless($oauthProvider->isConfigured(), Response::HTTP_NOT_FOUND);

        try {
            $socialiteUser = Socialite::driver($oauthProvider->value)->user();
        } catch (InvalidStateException) {
            Toast::error(__('Sign-in with :provider expired. Please try again.', [
                'provider' => $oauthProvider->label(),
            ]));

            return $this->failureRedirect($request);
        }

        if (! $socialiteUser instanceof SocialiteUser) {
            Toast::error(__('Sign-in with :provider expired. Please try again.', [
                'provider' => $oauthProvider->label(),
            ]));

            return $this->failureRedirect($request);
        }

        $authenticatedUser = $request->user();
        $linking = $request->session()->pull('oauth.intent') === 'link'
            && $authenticatedUser instanceof User;

        $result = $completeOAuthCallback->handle(
            $oauthProvider,
            $socialiteUser,
            $linking ? $authenticatedUser : null,
        );

        if (! $result->successful || ! $result->user instanceof User) {
            Toast::error($result->errorMessage ?? __('No Qompose account for this email.'));

            return $this->failureRedirect($request);
        }

        if ($linking) {
            Toast::success(__(':provider account linked.', [
                'provider' => $oauthProvider->label(),
            ]));

            return to_route('integrations.edit');
        }

        // Fortify 2FA challenge — same session key as password login.
        if (
            Features::enabled(Features::twoFactorAuthentication())
            && $result->user->hasEnabledTwoFactorAuthentication()
        ) {
            $request->session()->put([
                'login.id' => $result->user->getKey(),
                'login.remember' => false,
            ]);

            return to_route('two-factor.login');
        }

        Auth::login($result->user, remember: false);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    public function destroy(
        Request $request,
        string $provider,
        UnlinkOAuthConnectionAction $unlinkOAuthConnection,
    ): RedirectResponse {
        $oauthProvider = $this->resolveProvider($provider);
        $user = $this->currentUser();

        $unlinkOAuthConnection->handle($user, $oauthProvider);

        Toast::success(__(':provider account disconnected.', [
            'provider' => $oauthProvider->label(),
        ]));

        return back();
    }

    private function resolveProvider(string $provider): OAuthProvider
    {
        $oauthProvider = OAuthProvider::tryFrom($provider);

        abort_unless($oauthProvider instanceof OAuthProvider, Response::HTTP_NOT_FOUND);

        return $oauthProvider;
    }

    private function failureRedirect(Request $request): RedirectResponse
    {
        if ($request->user() instanceof User) {
            return to_route('integrations.edit');
        }

        return to_route('login');
    }
}
