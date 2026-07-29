<?php

declare(strict_types=1);

namespace App\Audit\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum AuditPermission: string implements TenantPermission
{
    case View = 'audit.view';

    public function label(): string
    {
        return __('View audit log');
    }

    public function description(): string
    {
        return __('Read the firm-wide record of who did what and when.');
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::Audit;
    }

    public function defaultRoles(): array
    {
        return [Role::Administrator];
    }
}
