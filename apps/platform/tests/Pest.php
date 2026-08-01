<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Actions\LogTenantActivityAction;
use App\Audit\Models\Activity;
use App\Dossiers\Models\Dossier;
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
use Illuminate\Support\Str;
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

// Feature tests hit the database by default; individual files must not re-declare this.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Policy and Query unit tests need the Laravel app + database.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Policies', 'Unit/Queries', 'Unit/Transitions');

// Model unit tests need the Laravel app for Eloquent, but not the database.
// The rest of Unit/ stays pure PHP (file inspection, mappers, config checks).
pest()->extend(TestCase::class)
    ->in('Unit/Models');

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

    // Per-controller action contracts live in tests/Unit/ControllerActionsTest.php;
    // one global allowlist here permitted any listed name on every controller.
    expect('App\*\Http\Controllers')
        ->classes()
        ->toExtend(Controller::class)
        ->toHaveSuffix('Controller')
        ->ignoring(Controller::class),

    expect(Activity::class)
        ->toExtend(Spatie\Activitylog\Models\Activity::class),

    /*
    |----------------------------------------------------------------------
    | Domain layering
    |----------------------------------------------------------------------
    |
    | Domains are ordered; a domain may use domains BELOW it and its own
    | layer siblings, never above. This is what stops the folder-based
    | domains from decaying back into one big namespace.
    |
    |   L0  Shared
    |   L1  Accounts
    |   L2  Tenancy
    |   L3  Questionnaires
    |   L4  Clients, Dossiers, DocumentProcessing
    |   L5  ClientPortal
    |   L6  Reporting, Platform, WorkspaceNotifications
    |
    |   Cross-cutting: Audit — any domain may log activity; Audit itself may
    |   only reach Accounts and Tenancy (enforced separately below).
    |
    | Three pairs share a layer because their dependency really is mutual, and
    | pretending otherwise only produced permanent "debt" entries:
    |
    |   Clients <-> Dossiers            a dossier is a case FOR a client; deleting
    |                                   a client cascades to its dossiers
    |   Dossiers <-> DocumentProcessing OCR/export pipelines act on uploads and
    |                                   dossiers, and are part of their lifecycle
    |
    | Audit was likewise modelled as a layer (L3) and generated ~20 violations,
    | because logging is genuinely cross-cutting: every domain writes activity,
    | while Audit needs User and Tenant to type its own rows.
    |
    | Each expectation below lists the upward domains it must not reach.
    |
    | Standing exception: Eloquent models may reference models from any
    | domain, because relations are inherently bidirectional. That covers
    | User -> Tenancy and Tenant -> Clients/Dossiers/Questionnaires.
    |
    */

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

    // User is the cross-domain hub, so it is ignored here and constrained
    // separately below. Audit is cross-cutting and deliberately absent.
    expect('App\Accounts')
        ->not->toUse([
            'App\ClientPortal',
            'App\Clients',
            'App\Dossiers',
            'App\DocumentProcessing',
            'App\Platform',
            'App\Questionnaires',
            'App\Reporting',
            'App\Tenancy',
            'App\WorkspaceNotifications',
        ])
        ->ignoring(User::class),

    // The one class allowed to reach up, and only as far as Tenancy: Eloquent
    // relations plus the SupportSessionState contract. Without this, ignoring
    // User above would let any upward dependency hide inside the hub.
    expect(User::class)
        ->not->toUse([
            'App\ClientPortal',
            'App\Clients',
            'App\Dossiers',
            'App\DocumentProcessing',
            'App\Platform',
            'App\Questionnaires',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    // Tenant's Eloquent relations reach Clients/Dossiers/Questionnaires under the
    // standing exception; Platform is inverted behind SupportSessionState.
    expect('App\Tenancy')
        ->not->toUse([
            'App\ClientPortal',
            'App\Clients',
            'App\DocumentProcessing',
            'App\Dossiers',
            'App\Platform',
            'App\Questionnaires',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ])
        ->ignoring(Tenant::class),

    // Cross-cutting, so anyone may use it — but it must stay cheap to depend on:
    // Audit itself may only reach Accounts and Tenancy.
    expect('App\Audit')
        ->not->toUse([
            'App\ClientPortal',
            'App\Clients',
            'App\Dossiers',
            'App\DocumentProcessing',
            'App\Platform',
            'App\Questionnaires',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    // Dossiers is a layer sibling (mutual by design). Reporting is now reached
    // through the TenantCacheInvalidator contract instead of a Reporting trait.
    expect('App\Clients')
        ->not->toUse([
            'App\ClientPortal',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    expect('App\Questionnaires')
        ->not->toUse([
            'App\ClientPortal',
            'App\DocumentProcessing',
            'App\Dossiers',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    // Clients and DocumentProcessing are layer siblings (mutual by design).
    // ClientPortal is reached only through DossierArchived / DocumentRequestRejected
    // events and the ClientPortalReminders contract; the Dossier model keeps its
    // ClientAccessGrant relation under the standing exception.
    expect('App\Dossiers')
        ->not->toUse([
            'App\ClientPortal',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ])
        ->ignoring(Dossier::class),

    expect('App\DocumentProcessing')
        ->not->toUse([
            'App\ClientPortal',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    expect('App\ClientPortal')
        ->not->toUse([
            'App\DocumentProcessing',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
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

/**
 * Grant an active exclusive builder lock so structure mutations authorize.
 */
function grantDossierBuilderLock(Dossier $dossier, User $user): string
{
    $token = (string) Str::ulid();

    $dossier->forceFill([
        'builder_locked_by_user_id' => $user->id,
        'builder_lock_token' => $token,
        'builder_lock_expires_at' => now()->addMinutes(5),
    ])->save();

    return $token;
}
