<?php

declare(strict_types=1);

namespace App\Tenancy\Contracts;

use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;
use BackedEnum;

/**
 * A single grantable ability within a firm (tenant).
 *
 * Each domain owns its own permission enum and implements this contract, so
 * Tenancy never needs to import domain namespaces. Extending BackedEnum both
 * guarantees a typed `->value` for the Spatie permission name and restricts
 * implementations to enums.
 *
 * Implementations must be string-backed: `->value` is the Spatie permission name.
 *
 * @property-read string $value
 */
interface TenantPermission extends BackedEnum
{
    /**
     * Human-readable name, used by role management surfaces.
     */
    public function label(): string;

    /**
     * Short explanation of what holding this permission allows.
     */
    public function description(): string;

    /**
     * Grouping used to organise permissions for display.
     */
    public function group(): PermissionGroup;

    /**
     * Roles that hold this permission by default.
     *
     * Owner is implicit and must not be listed: owners always hold every
     * registered permission.
     *
     * @return list<Role>
     */
    public function defaultRoles(): array;
}
