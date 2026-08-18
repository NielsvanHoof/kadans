<?php

declare(strict_types=1);

namespace App\Tenancy\Support;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;
use Illuminate\Support\Arr;
use InvalidArgumentException;

use function get_debug_type;
use function in_array;
use function is_a;
use function is_string;

/**
 * The authoritative view of every permission the application defines.
 *
 * Domains own their permission enums; this registry is the only thing that sees
 * all of them at once. Resolved once per worker from config, so it holds no
 * request state and is safe to share under Octane.
 */
final class PermissionRegistry
{
    /**
     * @var list<TenantPermission>|null
     */
    private ?array $permissions = null;

    /**
     * Takes the raw config value and validates it, so the entries are
     * deliberately untyped here rather than assumed to be enum class names.
     *
     * @param  array<mixed>  $enums
     */
    public function __construct(private readonly array $enums) {}

    /**
     * Every registered permission, in registration order.
     *
     * @return list<TenantPermission>
     */
    public function all(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        $permissions = [];

        foreach ($this->enums as $enum) {
            if (! is_string($enum) || ! is_a($enum, TenantPermission::class, allow_string: true)) {
                throw new InvalidArgumentException(
                    '['.(is_string($enum) ? $enum : get_debug_type($enum)).
                    '] is registered in config/tenant-permissions.php but does not implement '.
                    TenantPermission::class.'.',
                );
            }

            array_push($permissions, ...$enum::cases());
        }

        $this->guardAgainstDuplicateValues($permissions);

        return $this->permissions = $permissions;
    }

    /**
     * Spatie permission names for every registered permission.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return $this->valuesOf($this->all());
    }

    public function tryFrom(string $value): ?TenantPermission
    {
        return Arr::first(
            $this->all(),
            static fn (TenantPermission $permission): bool => $permission->value === $value,
        );
    }

    public function isRegistered(string $value): bool
    {
        return in_array($value, $this->values(), true);
    }

    /**
     * Permissions a role holds by default. Owners hold everything.
     *
     * @return list<TenantPermission>
     */
    public function forRole(Role $role): array
    {
        if ($role->grantsAllPermissions()) {
            return $this->all();
        }

        return array_values(array_filter(
            $this->all(),
            static fn (TenantPermission $permission): bool => in_array(
                $role,
                $permission->defaultRoles(),
                true,
            ),
        ));
    }

    /**
     * Owner-only permissions use empty defaultRoles(); owners still receive
     * them via Role::grantsAllPermissions(). They must never appear on
     * firm-authored (non-system) roles — that is the escalation path.
     */
    public function isOwnerOnly(TenantPermission $permission): bool
    {
        return $permission->defaultRoles() === [];
    }

    /**
     * Spatie names that may be granted on a firm-authored role.
     *
     * @return list<string>
     */
    public function firmAssignableValues(): array
    {
        return $this->valuesOf(array_values(array_filter(
            $this->all(),
            fn (TenantPermission $permission): bool => ! $this->isOwnerOnly($permission),
        )));
    }

    /**
     * Spatie permission names a role holds by default.
     *
     * @return list<string>
     */
    public function valuesForRole(Role $role): array
    {
        return $this->valuesOf($this->forRole($role));
    }

    /**
     * Permissions bucketed by display group.
     *
     * @return array<string, list<TenantPermission>>
     */
    public function grouped(): array
    {
        $groups = PermissionGroup::cases();

        usort(
            $groups,
            static fn (PermissionGroup $a, PermissionGroup $b): int => $a->sortOrder() <=> $b->sortOrder(),
        );

        $grouped = [];

        foreach ($groups as $group) {
            $matching = array_values(array_filter(
                $this->all(),
                static fn (TenantPermission $permission): bool => $permission->group() === $group,
            ));

            if ($matching !== []) {
                $grouped[$group->value] = $matching;
            }
        }

        return $grouped;
    }

    /**
     * Spatie permission names for a set of permissions.
     *
     * @param  list<TenantPermission>  $permissions
     * @return list<string>
     */
    private function valuesOf(array $permissions): array
    {
        return array_map(
            static fn (TenantPermission $permission): string => $permission->value,
            $permissions,
        );
    }

    /**
     * @param  list<TenantPermission>  $permissions
     */
    private function guardAgainstDuplicateValues(array $permissions): void
    {
        $seen = [];

        foreach ($permissions as $permission) {
            if (isset($seen[$permission->value])) {
                throw new InvalidArgumentException(
                    "Duplicate permission name [{$permission->value}] registered by ".
                    $seen[$permission->value].' and '.$permission::class.'.',
                );
            }

            $seen[$permission->value] = $permission::class;
        }
    }
}
