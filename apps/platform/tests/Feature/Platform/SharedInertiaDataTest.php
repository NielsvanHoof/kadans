<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Platform\Http\Middleware\HandleInertiaRequests;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\PermissionRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, string>  $additionalHeaders
 * @return array<string, string>
 */
function sharedDataInertiaHeaders(array $additionalHeaders = []): array
{
    return [
        Header::INERTIA => 'true',
        Header::VERSION => resolve(HandleInertiaRequests::class)->version(request()) ?? '',
        ...$additionalHeaders,
    ];
}

test('shared authenticated user data is explicitly projected', function (): void {
    $user = User::factory()->create();

    // Profile is always reachable for verified users (unlike `/`, which may redirect).
    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/profile')
            ->where('auth.user', [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->getAttributes()['locale'] ?? null,
                'is_platform_support' => false,
            ]));
});

/**
 * Permission names contain dots, which collide with Inertia's fluent path
 * syntax, so read the resolved props directly instead.
 *
 * @return array<string, bool>
 */
function sharedPermissionMap(TestResponse $response): array
{
    /** @var array{props: array{permissions?: array<string, bool>}} $page */
    $page = $response->viewData('page');

    return $page['props']['permissions'] ?? [];
}

test('the shared permission map resolves every registered permission for the firm', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $response = $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk();

    $permissions = sharedPermissionMap($response);
    $registered = resolve(PermissionRegistry::class)->values();

    // The map covers the registry exactly, and owners hold everything in it.
    expect(array_keys($permissions))->toEqualCanonicalizing($registered)
        ->and(array_keys(array_filter($permissions)))->toEqualCanonicalizing($registered);

    // The per-feature booleans this map replaced must be gone.
    /** @var array{props: array<string, mixed>} $page */
    $page = $response->viewData('page');

    expect($page['props'])->not->toHaveKey('can_manage_members')
        ->and($page['props'])->not->toHaveKey('can_view_roles')
        ->and($page['props'])->not->toHaveKey('can_view_pipelines')
        ->and($page['props'])->toHaveKey('sidebar_open');
});

test('the shared permission map denies what a restricted role cannot do', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $readOnly = workspaceMember($tenant, Role::ReadOnly);

    $response = $this->actingAs($readOnly)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk();

    $permissions = sharedPermissionMap($response);

    // members.view / roles.view / pipelines.view drive the sidebar and replaced
    // the removed can_* props, so their denial is what keeps nav items hidden.
    expect($permissions['members.view'])->toBeFalse()
        ->and($permissions['roles.view'])->toBeFalse()
        ->and($permissions['pipelines.view'])->toBeFalse()
        ->and($permissions['dossiers.delete'])->toBeFalse()
        ->and($permissions['dossiers.view'])->toBeTrue();
});

test('workspace navigation is remembered as a once prop', function (): void {
    $user = User::factory()->create();
    provisionWorkspace('Beta Tax', $user);
    provisionWorkspace('Acme Accountants', $user);
    // Firm list on profile is not workspace-scoped; clear in-process current tenant.
    Tenant::forgetCurrent();

    $this->actingAs($user)
        ->withHeaders(sharedDataInertiaHeaders())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertJsonPath('props.workspaces', [
            ['name' => 'Acme Accountants', 'slug' => 'acme-accountants'],
            ['name' => 'Beta Tax', 'slug' => 'beta-tax'],
        ])
        ->assertJsonPath('onceProps.workspaces.prop', 'workspaces');
});

test('remembered workspace navigation is not queried on every inertia response', function (): void {
    $user = User::factory()->create();
    // Two firms so session middleware does not auto-select a tenant (and query further).
    provisionWorkspace('Beta Tax', $user);
    provisionWorkspace('Acme Accountants', $user);
    Tenant::forgetCurrent();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->withHeaders(sharedDataInertiaHeaders([
            Header::EXCEPT_ONCE_PROPS => 'workspaces',
        ]))
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertJsonMissingPath('props.workspaces');

    // Navigation loads tenants (name/slug); session middleware only touches memberships.
    $workspaceNavigationQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from `tenants`')
            || str_contains($query['query'], 'from "tenants"'));

    expect($workspaceNavigationQueries)->toBeEmpty();
});

test('workspace navigation is refreshed after provisioning a workspace', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(sharedDataInertiaHeaders())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertJsonPath('props.workspaces', []);

    $this->post(route('onboarding.firm.store'), ['name' => 'Acme Accountants'])
        ->assertRedirect(workspaceRoute('workspaces.clients.create', 'acme-accountants'));

    $this->withHeaders(sharedDataInertiaHeaders([
        Header::EXCEPT_ONCE_PROPS => 'workspaces',
    ]))
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertJsonPath('props.workspaces', [
            ['name' => 'Acme Accountants', 'slug' => 'acme-accountants'],
        ]);
});
