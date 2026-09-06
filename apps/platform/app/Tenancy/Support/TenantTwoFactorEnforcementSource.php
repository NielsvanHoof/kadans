<?php

declare(strict_types=1);

namespace App\Tenancy\Support;

use App\Accounts\Contracts\TwoFactorEnforcementSource;
use App\Accounts\Enums\TwoFactorEnforcement;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\ResolveTenantSettingsQuery;
use Override;

/**
 * The current firm's own two-factor requirement, when there is a firm.
 *
 * Returns null off the workspace routes — the account area and the auth flow
 * run with no firm in context, and a member of two firms sitting on the
 * firm-picker has no single answer either.
 */
final readonly class TenantTwoFactorEnforcementSource implements TwoFactorEnforcementSource
{
    public function __construct(private ResolveTenantSettingsQuery $resolveTenantSettings) {}

    #[Override]
    public function forCurrentContext(): ?TwoFactorEnforcement
    {
        $tenant = Tenant::current();

        return $tenant instanceof Tenant
            ? $this->resolveTenantSettings->forTenantOrNull($tenant)?->two_factor_enforcement
            : null;
    }
}
