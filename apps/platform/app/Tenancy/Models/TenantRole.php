<?php

declare(strict_types=1);

namespace App\Tenancy\Models;

use App\Accounts\Models\User;
use App\Tenancy\Enums\Role as RoleEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Override;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A Spatie role scoped to one firm's permission team.
 *
 * Rows with `is_system = true` are owned by App\Tenancy\Enums\Role and
 * reconciled by SyncTenantRolesAction on every deploy. Rows with
 * `is_system = false` are firm-authored and never receive permissions from
 * code, so a firm can extend its role set without drifting from the presets.
 *
 * Registered as `permission.models.role`, so Spatie returns this class from
 * assignRole(), syncRoles(), and findByName() throughout the application.
 *
 * These @property tags are the one set still maintained by hand: Spatie's
 * migration creates the table as Schema::create($tableNames['roles'], ...),
 * a config lookup larastan cannot resolve statically, so the columns never
 * enter its scanned schema. Every other model relies on that scan instead.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $guard_name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property bool $is_system
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static>|TenantRole permission($permissions, bool $without = false)
 * @method static Builder<static> query()
 * @method static Builder<static>|TenantRole whereCreatedAt($value)
 * @method static Builder<static>|TenantRole whereGuardName($value)
 * @method static Builder<static>|TenantRole whereId($value)
 * @method static Builder<static>|TenantRole whereIsSystem($value)
 * @method static Builder<static>|TenantRole whereName($value)
 * @method static Builder<static>|TenantRole whereTenantId($value)
 * @method static Builder<static>|TenantRole whereUpdatedAt($value)
 * @method static Builder<static>|TenantRole withoutPermission($permissions)
 *
 * @mixin \Eloquent
 */
final class TenantRole extends SpatieRole
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'guard_name',
        'is_system',
        'tenant_id',
    ];

    /**
     * The code-defined preset this row represents, or null for a firm-authored role.
     */
    public function systemRole(): ?RoleEnum
    {
        if (! $this->is_system) {
            return null;
        }

        return RoleEnum::tryFrom($this->name);
    }

    public function isOwner(): bool
    {
        return $this->systemRole() === RoleEnum::Owner;
    }

    /**
     * Bind only roles for the current workspace.
     *
     * Spatie roles are team-scoped in queries we write, but route model binding
     * uses an unscoped find-by-id. Without this, another firm's role id would
     * resolve and rely on a controller abort.
     *
     * Query via self::query(), not $this->newQuery(). ide-helper marks
     * newQuery() as @method static, and phpstan-strict-rules then flags an
     * instance call as staticMethod.dynamicCall.
     */
    #[Override]
    public function resolveRouteBinding($value, $field = null): ?static
    {
        $field ??= $this->getRouteKeyName();
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        /** @var static|null $role */
        $role = self::query()
            ->where($this->qualifyColumn($field), $value)
            ->where($this->qualifyColumn('tenant_id'), $tenant->getKey())
            ->first();

        return $role;
    }

    /**
     * Translated preset label, falling back to the raw name for firm-authored roles.
     *
     * Presets resolve through Role::label() on every call so the label follows
     * the viewer's locale rather than whatever locale last wrote the row.
     */
    public function displayLabel(): string
    {
        return $this->systemRole()?->label() ?? $this->name;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }
}
