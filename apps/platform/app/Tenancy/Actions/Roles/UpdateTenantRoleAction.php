<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Models\TenantRole;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

use function array_diff;

final readonly class UpdateTenantRoleAction
{
    public function __construct(private LogAuditActivityAction $logAuditActivity) {}

    /**
     * @param  list<string>  $permissions
     */
    public function handle(TenantRole $role, User $updatedBy, string $name, array $permissions): TenantRole
    {
        return DB::transaction(function () use ($role, $updatedBy, $name, $permissions): TenantRole {
            $previousName = $role->name;
            /** @var list<string> $previousPermissions */
            $previousPermissions = $role->permissions
                ->pluck('name')
                ->values()
                ->all();

            $role->forceFill(['name' => $name])->saveOrFail();
            $role->syncPermissions($permissions);

            /** @var list<string> $addedPermissions */
            $addedPermissions = array_values(array_diff($permissions, $previousPermissions));
            /** @var list<string> $removedPermissions */
            $removedPermissions = array_values(array_diff($previousPermissions, $permissions));

            $this->logAuditActivity->handle(
                AuditEvent::RoleUpdated,
                null,
                [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'old_name' => $previousName,
                    'permissions' => $permissions,
                    'permissions_added' => $addedPermissions,
                    'permissions_removed' => $removedPermissions,
                ],
                $updatedBy,
            );

            resolve(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->fresh(['permissions']) ?? $role;
        });
    }
}
