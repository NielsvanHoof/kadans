<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Settings;

use App\Tenancy\Data\Settings\TenantSettingsData;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;

final readonly class UpdateTenantSettingsAction
{
    public function __construct(private ResolveTenantSettingsQuery $resolveTenantSettings) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Tenant $tenant, array $attributes): TenantSettingsData
    {
        // forTenant() rather than a firstOrCreate spelled out again here: the
        // shipped defaults belong in one place.
        $this->resolveTenantSettings->forTenant($tenant)->update($attributes);

        // The row's saved event has already dropped the cache entry by now.
        return $this->resolveTenantSettings->handle($tenant);
    }
}
