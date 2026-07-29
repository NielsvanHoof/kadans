<?php

declare(strict_types=1);

namespace App\Clients\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum ClientPermission: string implements TenantPermission
{
    case View = 'clients.view';
    case Create = 'clients.create';
    case Update = 'clients.update';
    case Delete = 'clients.delete';
    case Restore = 'clients.restore';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View clients'),
            self::Create => __('Add clients'),
            self::Update => __('Edit clients'),
            self::Delete => __('Archive clients'),
            self::Restore => __('Restore archived clients'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('Browse the client list and open client records.'),
            self::Create => __('Register a new client for the firm.'),
            self::Update => __('Change the details of a client.'),
            self::Delete => __('Archive clients, removing them from the active list.'),
            self::Restore => __('Return archived clients to the active list.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::Clients;
    }

    public function defaultRoles(): array
    {
        return [Role::Administrator];
    }
}
