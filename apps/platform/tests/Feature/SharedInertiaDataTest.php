<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Platform\Http\Middleware\HandleInertiaRequests;
use App\Tenancy\Actions\ProvisionTenantAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
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

test('workspace navigation is remembered as a once prop', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    resolve(ProvisionTenantAction::class)->handle('Beta Tax', $user);
    resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $user);

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
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    // Two firms so session middleware does not auto-select a tenant (and query further).
    resolve(ProvisionTenantAction::class)->handle('Beta Tax', $user);
    resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $user);

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
