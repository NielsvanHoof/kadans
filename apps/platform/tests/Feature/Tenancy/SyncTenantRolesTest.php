<?php

declare(strict_types=1);

use App\Audit\Enums\AuditPermission;
use App\Dossiers\Enums\DossierPermission;
use App\Tenancy\Actions\SyncTenantRolesAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

it('creates a permission row for every registered permission', function () {
    app(SyncTenantRolesAction::class)->handle();

    $stored = PermissionModel::query()->where('guard_name', 'web')->pluck('name')->all();

    expect($stored)->toEqualCanonicalizing(app(PermissionRegistry::class)->values());
});

it('is a no-op when run twice', function () {
    app(SyncTenantRolesAction::class)->handle();

    $second = app(SyncTenantRolesAction::class)->handle();

    expect($second['permissions_created'])->toBe(0)
        ->and($second['roles_created'])->toBe(0);
});

it('gives each role exactly the permissions the registry declares', function () {
    ['tenant' => $tenant] = provisionWorkspace();

    $registry = app(PermissionRegistry::class);

    foreach (Role::cases() as $roleEnum) {
        $role = RoleModel::query()
            ->where('name', $roleEnum->value)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        expect($role->permissions->pluck('name')->all())
            ->toEqualCanonicalizing(
                $registry->valuesForRole($roleEnum),
                "role [{$roleEnum->value}] permissions drifted",
            );
    }
});

it('grants a permission that a role is newly entitled to', function () {
    ['tenant' => $tenant] = provisionWorkspace();

    $role = RoleModel::query()
        ->where('name', Role::ReadOnly->value)
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    // Read-only should not be able to create dossiers.
    $role->revokePermissionTo(DossierPermission::View->value);
    expect($role->fresh()->permissions->pluck('name'))
        ->not->toContain(DossierPermission::View->value);

    app(SyncTenantRolesAction::class)->syncTenant($tenant);

    // Sync restores what the registry declares.
    expect($role->fresh()->permissions->pluck('name'))
        ->toContain(DossierPermission::View->value);
});

it('revokes a permission a role should no longer hold', function () {
    ['tenant' => $tenant] = provisionWorkspace();

    $role = RoleModel::query()
        ->where('name', Role::ReadOnly->value)
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    $role->givePermissionTo(DossierPermission::Delete->value);
    expect($role->fresh()->permissions->pluck('name'))
        ->toContain(DossierPermission::Delete->value);

    app(SyncTenantRolesAction::class)->syncTenant($tenant);

    expect($role->fresh()->permissions->pluck('name'))
        ->not->toContain(DossierPermission::Delete->value);
});

it('reaches firms that already existed before a permission was added', function () {
    ['tenant' => $tenantA] = provisionWorkspace('Firm A');
    ['tenant' => $tenantB] = provisionWorkspace('Firm B');

    // Simulate a deploy where both firms have drifted from the new declaration.
    foreach ([$tenantA, $tenantB] as $tenant) {
        RoleModel::query()
            ->where('name', Role::Adviser->value)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail()
            ->syncPermissions([]);
    }

    app(SyncTenantRolesAction::class)->handle();

    $expected = app(PermissionRegistry::class)->valuesForRole(Role::Adviser);

    foreach ([$tenantA, $tenantB] as $tenant) {
        $role = RoleModel::query()
            ->where('name', Role::Adviser->value)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        expect($role->permissions->pluck('name')->all())->toEqualCanonicalizing($expected);
    }
});

it('prunes permissions that are no longer declared in code only when asked', function () {
    app(SyncTenantRolesAction::class)->handle();

    PermissionModel::query()->create(['name' => 'legacy.permission', 'guard_name' => 'web']);

    $withoutPrune = app(SyncTenantRolesAction::class)->handle();

    expect($withoutPrune['permissions_pruned'])->toBe(0)
        ->and(PermissionModel::query()->where('name', 'legacy.permission')->exists())->toBeTrue();

    $withPrune = app(SyncTenantRolesAction::class)->handle(prune: true);

    expect($withPrune['permissions_pruned'])->toBe(1)
        ->and(PermissionModel::query()->where('name', 'legacy.permission')->exists())->toBeFalse();
});

it('restores the previous permission team context after syncing', function () {
    ['tenant' => $tenant] = provisionWorkspace();

    $before = getPermissionsTeamId();

    app(SyncTenantRolesAction::class)->syncTenant($tenant);

    expect(getPermissionsTeamId())->toBe($before);
});

it('resolves a role assignment into the individual permissions it grants', function () {
    ['tenant' => $tenant] = provisionWorkspace();

    $adviser = workspaceMember($tenant, Role::Adviser);

    expect($adviser->hasTenantPermission(DossierPermission::View, $tenant))->toBeTrue()
        ->and($adviser->hasTenantPermission(DossierPermission::Create, $tenant))->toBeTrue()
        ->and($adviser->hasTenantPermission(DossierPermission::Update, $tenant))->toBeTrue()
        ->and($adviser->hasTenantPermission(DossierPermission::Export, $tenant))->toBeTrue()
        ->and($adviser->hasTenantPermission(WorkspacePermission::AssignMemberRoles, $tenant))->toBeFalse()
        ->and($adviser->hasTenantPermission(AuditPermission::View, $tenant))->toBeFalse();
});

it('reports what it changed via the console command', function () {
    artisan('tenancy:sync-roles')
        ->assertSuccessful();

    artisan('tenancy:sync-roles', ['--prune' => true])
        ->assertSuccessful();
});
