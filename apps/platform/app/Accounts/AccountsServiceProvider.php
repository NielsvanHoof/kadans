<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Accounts\Contracts\TwoFactorEnforcementSource;
use App\Accounts\Support\Security\InheritPlatformTwoFactorEnforcement;
use Illuminate\Support\ServiceProvider;
use Override;

final class AccountsServiceProvider extends ServiceProvider
{
    /**
     * Binds the default that asks nothing beyond the platform setting.
     *
     * TenancyServiceProvider rebinds this to the firm-aware implementation, and
     * the last binding wins — so this provider must stay registered *before*
     * Tenancy in bootstrap/providers.php. Reversing them would silently drop
     * every firm's two-factor choice, which is why
     * tests/Feature/Accounts/TwoFactorEnforcementSourceBindingTest.php asserts
     * which implementation actually resolves.
     */
    #[Override]
    public function register(): void
    {
        $this->app->scoped(
            TwoFactorEnforcementSource::class,
            InheritPlatformTwoFactorEnforcement::class,
        );
    }
}
