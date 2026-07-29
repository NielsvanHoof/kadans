<?php

declare(strict_types=1);

namespace App\ClientPortal\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum ClientAccessGrantPermission: string implements TenantPermission
{
    case Grant = 'client_access.grant';
    case Revoke = 'client_access.revoke';

    public function label(): string
    {
        return match ($this) {
            self::Grant => __('Grant portal access'),
            self::Revoke => __('Revoke portal access'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Grant => __('Issue a client portal link so a client can upload documents.'),
            self::Revoke => __('Withdraw a portal link, cutting off access for that client.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::ClientPortal;
    }

    public function defaultRoles(): array
    {
        return [Role::Administrator, Role::Adviser];
    }
}
