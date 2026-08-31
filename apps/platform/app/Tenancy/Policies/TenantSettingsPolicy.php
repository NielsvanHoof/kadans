<?php

declare(strict_types=1);

namespace App\Tenancy\Policies;

use App\Accounts\Models\User;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class TenantSettingsPolicy
{
    use AuthorizesTenantPermission;

    public function view(User $user, TenantSetting $settings): bool
    {
        $tenant = Tenant::query()->find($settings->tenant_id);

        return $this->userHasPermissionInTenant(
            $user,
            $tenant,
            WorkspacePermission::ManageSettings,
        );
    }

    public function update(User $user, TenantSetting $settings): bool
    {
        $tenant = Tenant::query()->find($settings->tenant_id);

        return $this->userHasPermissionInTenant(
            $user,
            $tenant,
            WorkspacePermission::ManageSettings,
        );
    }
}
