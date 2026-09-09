<?php

declare(strict_types=1);

namespace App\Providers;

use App\Accounts\Actions\Profile\CreateNewUser;
use App\Accounts\Actions\Security\ResetUserPassword;
use App\Integrations\Enums\OAuthProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Override;

use function is_string;

final class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configurePasswordResetThrottling();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'oauthProviders' => array_map(
                static fn (OAuthProvider $provider): string => $provider->value,
                OAuthProvider::configured(),
            ),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');
            $rateLimitKey = is_string($credentialId) && $credentialId !== ''
                ? $credentialId
                : $request->session()->getId();

            return Limit::perMinute(10)->by($rateLimitKey.'|'.$request->ip());
        });

        // OAuth redirect + callback — throttle by IP like login.
        RateLimiter::for(
            'oauth',
            fn (Request $request) => Limit::perMinute(10)->by('oauth|'.$request->ip()),
        );

        RateLimiter::for('reset-password-email', function (Request $request): array {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::email())));

            // Two limits stacked: a per-email cap stops one address being
            // mail-bombed, a per-IP cap stops one attacker spraying reset
            // emails across every address in a firm.
            return [
                Limit::perMinute(3)->by('reset-password-email|email|'.$email),
                Limit::perMinute(10)->by('reset-password-email|ip|'.$request->ip()),
            ];
        });

        RateLimiter::for(
            'reset-password-update',
            fn (Request $request) => Limit::perMinute(10)->by('reset-password-update|'.$request->ip()),
        );
    }

    /**
     * Fortify has no config-driven limiter hook for the password reset
     * routes (only login/two-factor/passkeys read fortify.limiters.*), so
     * the throttle middleware is attached directly to the already-registered
     * routes here, after the package's own service provider has booted.
     *
     * Route names are only indexed lazily — RouteCollection::nameList is
     * rebuilt from Route::name() the first time routes are matched against
     * a real request (toSymfonyRouteCollection()), not at registration
     * time — so getByName() would miss these routes without an explicit
     * refresh this early in the boot cycle.
     */
    private function configurePasswordResetThrottling(): void
    {
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();

        $routes->getByName('password.email')?->middleware('throttle:reset-password-email');
        $routes->getByName('password.update')?->middleware('throttle:reset-password-update');
    }
}
