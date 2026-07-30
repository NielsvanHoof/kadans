<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final readonly class DeleteTenantRoleAction
{
    public function __construct(private LogAuditActivityAction $logAuditActivity) {}

    public function handle(Tenant $tenant, TenantRole $role, User $deletedBy): void
    {
        $teamForeignKey = (string) config('permission.column_names.team_foreign_key');
        $rolesTable = (string) config('permission.table_names.roles');
        $modelHasRolesTable = (string) config('permission.table_names.model_has_roles');
        $modelKey = (string) config('permission.column_names.model_morph_key');

        $isAssignedToActiveMember = DB::table('tenant_memberships')
            ->join($modelHasRolesTable, function ($join) use ($tenant, $teamForeignKey, $modelHasRolesTable, $modelKey): void {
                $join->on('tenant_memberships.user_id', '=', "{$modelHasRolesTable}.{$modelKey}")
                    ->where("{$modelHasRolesTable}.model_type", (new User)->getMorphClass())
                    ->where("{$modelHasRolesTable}.{$teamForeignKey}", $tenant->id);
            })
            ->join($rolesTable, "{$rolesTable}.id", '=', "{$modelHasRolesTable}.role_id")
            ->where('tenant_memberships.tenant_id', $tenant->id)
            ->where('tenant_memberships.status', TenantMembershipStatus::Active->value)
            ->where("{$rolesTable}.id", $role->id)
            ->where(function (Builder $query) use ($rolesTable, $teamForeignKey, $tenant): void {
                $query->whereNull("{$rolesTable}.{$teamForeignKey}")
                    ->orWhere("{$rolesTable}.{$teamForeignKey}", $tenant->id);
            })
            ->exists();

        if ($isAssignedToActiveMember) {
            throw ValidationException::withMessages([
                'role' => __('This role is still assigned to active members. Reassign them first.'),
            ]);
        }

        /** @var list<string> $permissions */
        $permissions = $role->permissions
            ->pluck('name')
            ->values()
            ->all();

        $this->logAuditActivity->handle(
            AuditEvent::RoleDeleted,
            null,
            [
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'role_name' => $role->name,
                'permissions' => $permissions,
            ],
            $deletedBy,
        );

        $role->delete();
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
