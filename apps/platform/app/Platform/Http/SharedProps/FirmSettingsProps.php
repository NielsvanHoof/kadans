<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Models\User;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;

/**
 * Firm-wide settings visible to every member in the current workspace.
 *
 * Owners change these on the firm settings page; other staff need the flags
 * to hide AI affordances without calling gated endpoints first.
 */
final readonly class FirmSettingsProps
{
    public function __construct(private ResolveTenantSettingsQuery $resolveTenantSettings) {}

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return $this->resolveTenantSettings->handle($tenant)->toArray();
    }
}
