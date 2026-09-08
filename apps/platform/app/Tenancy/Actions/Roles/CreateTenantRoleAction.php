<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final readonly class CreateTenantRoleAction
{
    public function __construct(private LogAuditActivityAction $logAuditActivity) {}

    /**
     * @param  list<string>  $permissions
     */
    public function handle(Tenant $tenant, User $createdBy, string $name, array $permissions): TenantRole
    {
        return DB::transaction(function () use ($tenant, $createdBy, $name, $permissions): TenantRole {
            $role = TenantRole::query()->create([
                'name' => $name,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
                'is_system' => false,
            ]);

            $role->syncPermissions($permissions);

            $this->logAuditActivity->handle(
                AuditEvent::RoleCreated,
                null,
                [
                    'tenant_id' => $tenant->id,
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'permissions' => $permissions,
                ],
                $createdBy,
            );

            resolve(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }
}
