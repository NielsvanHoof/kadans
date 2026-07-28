<?php

declare(strict_types=1);

namespace App\Policies\Reporting;

use App\Enums\Permission;
use App\Models\Pipeline;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

final class PipelinePolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::ViewAuditLog);
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return $this->userHasPermissionInTenant($user, $pipeline->tenant, Permission::ViewAuditLog);
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $pipeline->status->isTerminal()
            && $this->userHasPermissionInTenant($user, $pipeline->tenant, Permission::CreateDossiers);
    }
}
