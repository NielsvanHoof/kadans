<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Actions\LogTenantActivityAction;
use App\Audit\Models\Activity;
use App\Reporting\Queries\ActivityLogRowMapper;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Queries\PaginatedIndexQuery;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

// Policy and Query unit tests need the Laravel app + database.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Policies', 'Unit/Queries');

pest()->presets()->custom('qompose', fn (): array => [
    expect('App')
        ->toUseStrictTypes(),

    expect('App\*\Actions')
        ->classes()
        ->toBeFinal(),

    // Domain actions end in Action; Fortify Accounts contracts keep framework names.
    expect('App\*\Actions')
        ->classes()
        ->toHaveSuffix('Action')
        ->ignoring([
            'App\Accounts\Actions',
        ]),

    expect('App\*\Actions')
        ->classes()
        ->toHaveMethod('handle')
        ->ignoring([
            'App\Accounts\Actions',
            LogTenantActivityAction::class,
        ]),

    expect('App\*\Actions')
        ->classes()
        ->not->toHaveMethod('__invoke')
        ->ignoring([
            'App\Accounts\Actions',
            LogTenantActivityAction::class,
        ]),

    // Read models end in Query; Spatie filters and row mappers are not Queries.
    expect('App\*\Queries')
        ->classes()
        ->toHaveSuffix('Query')
        ->ignoring([
            'App\Shared\Queries\Filters',
            ActivityLogRowMapper::class,
        ]),

    expect('App\*\Queries')
        ->classes()
        ->toHaveMethod('handle')
        ->ignoring([
            PaginatedIndexQuery::class,
            'App\Shared\Queries\Filters',
            ActivityLogRowMapper::class,
        ]),

    expect('App\*\Queries')
        ->classes()
        ->not->toHaveMethod('__invoke')
        ->ignoring([
            PaginatedIndexQuery::class,
            'App\Shared\Queries\Filters',
            ActivityLogRowMapper::class,
        ]),

    expect('App\*\Queries')
        ->classes()
        ->toBeFinal()
        ->ignoring([
            PaginatedIndexQuery::class,
        ]),

    expect(Controller::class)
        ->toBeAbstract(),

    expect('App\*\Http\Controllers')
        ->classes()
        ->toExtend(Controller::class)
        ->toHaveSuffix('Controller')
        ->not->toHavePublicMethodsBesides([
            '__construct',
            '__invoke',
            'index',
            'show',
            'create',
            'store',
            'edit',
            'update',
            'destroy',
            'middleware',
            // Domain-specific controller actions beyond REST defaults.
            'copy',
            'reorder',
            'applyTemplate',
            'answer',
            'download',
            'archived',
            'restore',
            'bulkArchive',
            'bulkRestore',
            'resend',
            'accept',
            'register',
            'builder',
            'review',
            'preview',
        ])
        ->ignoring(Controller::class),

    expect(Activity::class)
        ->toExtend(Spatie\Activitylog\Models\Activity::class),

    expect('App\Shared')
        ->not->toUse([
            'App\Accounts',
            'App\Audit',
            'App\ClientPortal',
            'App\Clients',
            'App\Dossiers',
            'App\DocumentProcessing',
            'App\WorkspaceNotifications',
            'App\Platform',
            'App\Questionnaires',
            'App\Reporting',
            'App\Tenancy',
        ]),
]);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Shared helpers for Feature and Unit tests. Prefer these over repeating
| ProvisionTenantAction + membership + role setup in every file.
|
*/

/**
 * @param  array<string, mixed>  $parameters
 */
function workspaceRoute(
    string $name,
    Tenant|string $tenant,
    array $parameters = [],
): string {
    return route($name, ['tenant' => $tenant, ...$parameters]);
}

/**
 * Seed roles/permissions, provision a firm, and activate its tenant context.
 *
 * @return array{owner: User, tenant: Tenant}
 */
function provisionWorkspace(string $name = 'Acme Accountants', ?User $owner = null): array
{
    test()->seed(RolesAndPermissionsSeeder::class);

    $owner ??= User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle($name, $owner);

    actingInWorkspace($tenant);

    return [
        'owner' => $owner->fresh(),
        'tenant' => $tenant,
    ];
}

/**
 * Set the current Spatie tenant + permission team for the request lifecycle.
 */
function actingInWorkspace(Tenant $tenant): void
{
    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);
}

/**
 * Attach an active membership and assign a tenant-scoped role.
 */
function workspaceMember(Tenant $tenant, Role $role, ?User $user = null): User
{
    $user ??= User::factory()->create();

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    actingInWorkspace($tenant);
    $user->assignRole($role->value);

    return $user->fresh();
}
