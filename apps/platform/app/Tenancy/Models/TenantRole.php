<?php

declare(strict_types=1);

namespace App\Tenancy\Models;

use App\Tenancy\Enums\Role as RoleEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Override;
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
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $guard_name
 * @property bool $is_system
 */
#[Fillable(
    ['name', 'guard_name', 'is_system', 'tenant_id'],
)]
final class TenantRole extends SpatieRole
{
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
