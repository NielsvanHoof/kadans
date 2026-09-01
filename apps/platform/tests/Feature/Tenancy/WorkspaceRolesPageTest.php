<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Dossiers\Enums\DossierPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Support\PermissionRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Roles that hold a permission, as the page reports them.
 *
 * @param  array<int, mixed>  $groups
 * @return list<string>
 */
function grantedRolesFor(array $groups, string $permissionValue): array
{
    foreach ($groups as $group) {
        foreach ($group['permissions'] as $permission) {
            if ($permission['value'] === $permissionValue) {
                return $permission['granted_to'];
            }
        }
    }

    return [];
}

test('owners see every role and permission group', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $expectedGroups = array_keys(resolve(PermissionRegistry::class)->grouped());

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/roles/index')
            ->has('roles', count(Role::cases()))
            ->has('permission_groups', count($expectedGroups))
            ->where('can_manage_roles', true)
            ->where('roles.0.value', Role::Owner->value)
            ->where('roles.0.is_system', true));
});

test('administrators can view roles because roles.view is a default', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    $this->actingAs($admin)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('can_manage_roles', false));
});

test('owners can open the dedicated custom roles management page', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.roles.manage', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/roles/manage')
            ->where('can_manage_roles', true));
});

test('administrators cannot open the dedicated custom roles management page', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    $this->actingAs($admin)
        ->get(workspaceRoute('workspaces.roles.manage', $tenant))
        ->assertForbidden();
});

test('members without roles.view cannot open the page', function (Role $role): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, $role);

    $this->actingAs($member)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertForbidden();
})->with([
    'adviser' => Role::Adviser,
    'reviewer' => Role::Reviewer,
    'read-only' => Role::ReadOnly,
]);

test('managing roles stays owner-only', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    expect(WorkspacePermission::ManageRoles->defaultRoles())
        ->toBeEmpty()
        ->and($owner->hasTenantPermission(WorkspacePermission::ManageRoles, $tenant))->toBeTrue()
        ->and($admin->hasTenantPermission(WorkspacePermission::ManageRoles, $tenant))->toBeFalse();
});

test('the matrix reports persisted grants rather than registry defaults', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    // Revoke straight from the pivot, the way drift would actually appear.
    TenantRole::query()
        ->where('name', Role::Adviser->value)
        ->where('tenant_id', $tenant->id)
        ->firstOrFail()
        ->revokePermissionTo(DossierPermission::Export->value);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            /** @var array<int, mixed> $groups */
            $groups = $page->toArray()['props']['permission_groups'];

            expect(grantedRolesFor($groups, DossierPermission::Export->value))
                ->not->toContain(Role::Adviser->value)
                ->toContain(Role::Administrator->value)
                ->toContain(Role::Owner->value);
        });
});

test('owners hold every registered permission on the matrix', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $registry = resolve(PermissionRegistry::class);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($registry): void {
            /** @var array<int, mixed> $groups */
            $groups = $page->toArray()['props']['permission_groups'];

            $missing = array_values(array_filter(
                $registry->values(),
                static fn (string $permissionValue): bool => ! in_array(
                    Role::Owner->value,
                    grantedRolesFor($groups, $permissionValue),
                    true,
                ),
            ));

            expect($missing)
                ->toBeEmpty();
        });
});

test('firm settings show as owner and administrator on the matrix', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            /** @var array<int, mixed> $groups */
            $groups = $page->toArray()['props']['permission_groups'];

            expect(grantedRolesFor($groups, WorkspacePermission::ManageSettings->value))
                ->toBe([Role::Owner->value, Role::Administrator->value]);
        });
});

test('roles carry the count of active members holding them', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    workspaceMember($tenant, Role::Adviser);
    workspaceMember($tenant, Role::Adviser);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            /** @var array<int, mixed> $roles */
            $roles = $page->toArray()['props']['roles'];

            $countsByRole = [];

            foreach ($roles as $role) {
                $countsByRole[$role['value']] = $role['member_count'];
            }

            expect($countsByRole[Role::Adviser->value])->toBe(2)
                ->and($countsByRole[Role::Owner->value])->toBe(1)
                ->and($countsByRole[Role::Reviewer->value])->toBe(0);
        });
});

test('the page does not scale queries with the number of permissions', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $roleQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$roleQueries): void {
        if (str_contains($query->sql, 'role_has_permissions') || str_contains($query->sql, 'model_has_roles')) {
            $roleQueries[] = $query->sql;
        }
    });

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertOk();

    // One permission-pivot eager load and one member-count join, plus the
    // viewer's own role lookup for the gate — never one query per role.
    expect(count($roleQueries))->toBeLessThanOrEqual(4);
});

test('a firm cannot see another firms roles', function (): void {
    ['tenant' => $first] = provisionWorkspace('First Firm');
    ['owner' => $secondOwner, 'tenant' => $second] = provisionWorkspace('Second Firm');

    // A role that only the first firm has, to prove the page is tenant-scoped.
    TenantRole::query()->create([
        'name' => 'first-firm-only',
        'guard_name' => 'web',
        'tenant_id' => $first->id,
        'is_system' => false,
    ]);

    actingInWorkspace($second);

    $this->actingAs($secondOwner)
        ->get(workspaceRoute('workspaces.roles.index', $second))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            /** @var array<int, mixed> $roles */
            $roles = $page->toArray()['props']['roles'];

            expect(array_column($roles, 'value'))->not->toContain('first-firm-only');
        });
});

test('permission groups are ordered for display', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.roles.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('permission_groups.0.value', PermissionGroup::Dossiers->value)
            ->where('permission_groups.0.label', PermissionGroup::Dossiers->label()));
});

test('the roles capability is shared with the frontend so the sidebar can gate on it', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $readOnly = workspaceMember($tenant, Role::ReadOnly);

    // Read via the props array: permission names contain dots, which Inertia's
    // fluent assertions would treat as a nested path.
    $permissionsFor = function (User $user) use ($tenant): array {
        /** @var array{props: array{permissions?: array<string, bool>}} $page */
        $page = $this->actingAs($user)
            ->get(workspaceRoute('workspaces.dashboard', $tenant))
            ->assertOk()
            ->viewData('page');

        return $page['props']['permissions'] ?? [];
    };

    expect($permissionsFor($owner)[WorkspacePermission::ViewRoles->value])->toBeTrue()
        ->and($permissionsFor($readOnly)[WorkspacePermission::ViewRoles->value])->toBeFalse();
});

test('owners can create update and delete custom roles', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.roles.store', $tenant), [
            'name' => 'operations-manager',
            'permissions' => [
                WorkspacePermission::ViewMembers->value,
                WorkspacePermission::InviteMembers->value,
            ],
        ])
        ->assertRedirect(workspaceRoute('workspaces.roles.manage', $tenant));

    $customRole = TenantRole::query()
        ->where('tenant_id', $tenant->id)
        ->where('name', 'operations-manager')
        ->firstOrFail();

    expect($customRole->is_system)->toBeFalse()
        ->and($customRole->hasPermissionTo(WorkspacePermission::ViewMembers->value))->toBeTrue()
        ->and($customRole->hasPermissionTo(WorkspacePermission::InviteMembers->value))->toBeTrue();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.roles.update', $tenant, [
            'role' => $customRole,
        ]), [
            'name' => 'operations-lead',
            'permissions' => [
                WorkspacePermission::ViewMembers->value,
            ],
        ])
        ->assertRedirect(workspaceRoute('workspaces.roles.manage', $tenant));

    $customRole = $customRole->fresh();

    expect($customRole)->not->toBeNull()
        ->and($customRole?->name)->toBe('operations-lead')
        ->and($customRole?->hasPermissionTo(WorkspacePermission::ViewMembers->value))->toBeTrue()
        ->and($customRole?->hasPermissionTo(WorkspacePermission::InviteMembers->value))->toBeFalse();

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.roles.destroy', $tenant, [
            'role' => $customRole,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.roles.manage', $tenant));

    expect(TenantRole::query()->whereKey($customRole?->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', AuditEvent::RoleCreated->value)
            ->exists())
        ->toBeTrue()
        ->and(Activity::query()->where('event', AuditEvent::RoleUpdated->value)
            ->exists())
        ->toBeTrue()
        ->and(Activity::query()->where('event', AuditEvent::RoleDeleted->value)
            ->exists())
        ->toBeTrue();
});

test('non role managers cannot mutate roles', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    $this->actingAs($admin)
        ->post(workspaceRoute('workspaces.roles.store', $tenant), [
            'name' => 'forbidden-role',
            'permissions' => [WorkspacePermission::ViewMembers->value],
        ])
        ->assertForbidden();
});

test('system roles cannot be updated or deleted', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $systemRole = TenantRole::query()
        ->where('tenant_id', $tenant->id)
        ->where('name', Role::Administrator->value)
        ->firstOrFail();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.roles.update', $tenant, [
            'role' => $systemRole,
        ]), [
            'name' => 'admin-renamed',
            'permissions' => [WorkspacePermission::ViewMembers->value],
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.roles.destroy', $tenant, [
            'role' => $systemRole,
        ]))
        ->assertForbidden();
});

test('custom roles in use cannot be deleted', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, Role::Reviewer);

    $customRole = TenantRole::query()->create([
        'name' => 'temp-ops',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
        'is_system' => false,
    ]);
    $customRole->syncPermissions([WorkspacePermission::ViewMembers->value]);

    actingInWorkspace($tenant);
    $member->syncRoles([$customRole->name]);

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.roles.destroy', $tenant, [
            'role' => $customRole,
        ]))
        ->assertSessionHasErrors('role')
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash(
            'toast.message',
            __('This role is still assigned to active members. Reassign them first.'),
        );

    expect($customRole->fresh())->not->toBeNull();
});
