<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Models\User;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\ResolveTenantSettingsQuery;

/**
 * The firm settings every member needs on every page.
 *
 * Staff need the AI flags to hide affordances rather than discover them by
 * calling a disabled endpoint, and the reminder default to prefill a new
 * dossier. Everything else a firm configures — its security, portal and
 * retention policy — ships only to the settings page: this prop is on every
 * authenticated response and goes to every role.
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

        return $this->resolveTenantSettings->shared($tenant)->toArray();
    }
}
