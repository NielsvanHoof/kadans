<?php

declare(strict_types=1);

namespace App\Integrations;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider;

/**
 * OAuth providers and connected-account bindings.
 *
 * Integrations is L1 (sibling of Accounts): login and settings call into
 * this domain for Google / Microsoft OAuth connections. Calendar product
 * UI stays out — it will live higher and reuse the token vault.
 */
final class IntegrationsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Google is a built-in Socialite driver; Microsoft needs this package.
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', Provider::class);
        });
    }
}
