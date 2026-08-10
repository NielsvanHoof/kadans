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
use Illuminate\Support\Env;
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

// Model unit tests need the Laravel app for Eloquent, but not the database.
// The rest of Unit/ stays pure PHP (file inspection, mappers, config checks) — Unit tests
// must never touch the database, cache, or HTTP; Policy/Query/Transition coverage lives
// in Feature/{Domain} instead, since those need RefreshDatabase.
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
    |   L4  Clients, Dossiers, DocumentProcessing, AudioProcessing, Pipelines
    |   L5  ClientPortal
    |   L6  Reporting, Platform, WorkspaceNotifications
    |
    |   Cross-cutting: Audit — any domain may log activity; Audit itself may
    |   only reach Accounts and Tenancy (enforced separately below).
    |
    | Several pairs share a layer because their dependency really is mutual, and
    | pretending otherwise only produced permanent "debt" entries:
    |
    |   Clients <-> Dossiers             a dossier is a case FOR a client; deleting
    |                                    a client cascades to its dossiers
    |   Dossiers <-> DocumentProcessing  uploads carry OCR state that is part of
    |                                    their lifecycle
    |   Dossiers <-> AudioProcessing     audio recordings carry transcription state
    |                                    the same way
    |   Dossiers <-> Pipelines           export runs are pipelines owned by a
    |                                    dossier; PipelinePolicy::download also
    |                                    gates on DossierPermission
    |
    | DocumentProcessing and AudioProcessing each depend on Pipelines one-way (run
    | tracking), registering a PipelineRetryRunner into Pipelines' registry rather
    | than Pipelines knowing about OCR/transcription specifics.
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
            'App\AudioProcessing',
            'App\ClientPortal',
            'App\Clients',
            'App\Dossiers',
            'App\DocumentProcessing',
            'App\Pipelines',
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
            'App\AudioProcessing',
            'App\ClientPortal',
            'App\Clients',
            'App\Dossiers',
            'App\DocumentProcessing',
            'App\Pipelines',
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
            'App\AudioProcessing',
            'App\ClientPortal',
            'App\Clients',
            'App\Dossiers',
            'App\DocumentProcessing',
            'App\Pipelines',
            'App\Platform',
            'App\Questionnaires',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    // Tenant's Eloquent relations reach Clients/Dossiers/Questionnaires under the
    // standing exception; Platform is inverted behind SupportSessionState.
    expect('App\Tenancy')
        ->not->toUse([
            'App\AudioProcessing',
            'App\ClientPortal',
            'App\Clients',
            'App\DocumentProcessing',
            'App\Dossiers',
            'App\Pipelines',
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
            'App\AudioProcessing',
            'App\ClientPortal',
            'App\Clients',
            'App\Dossiers',
            'App\DocumentProcessing',
            'App\Pipelines',
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
            'App\AudioProcessing',
            'App\ClientPortal',
            'App\DocumentProcessing',
            'App\Dossiers',
            'App\Pipelines',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    // Clients, DocumentProcessing, AudioProcessing, and Pipelines are layer
    // siblings (mutual by design). ClientPortal is reached only through
    // DossierArchived / DocumentRequestRejected events and the
    // ClientPortalReminders contract; the Dossier model keeps its
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

    // Registers a PipelineRetryRunner into Pipelines' registry; otherwise
    // unremarkable as an L4 sibling.
    expect('App\AudioProcessing')
        ->not->toUse([
            'App\ClientPortal',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    // Depends on Dossiers only for PipelinePolicy::download's DossierPermission
    // check; OCR/transcription specifics stay inverted behind PipelineRetryRunner.
    expect('App\Pipelines')
        ->not->toUse([
            'App\ClientPortal',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    expect('App\ClientPortal')
        ->not->toUse([
            'App\AudioProcessing',
            'App\DocumentProcessing',
            'App\Pipelines',
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
 * Re-evaluate a config file under temporary environment variables.
 *
 * For config that branches on the environment (`env('APP_ENV') === 'production'`)
 * and so cannot be exercised through `config()`, which is already resolved.
 *
 * Two traps this exists to contain:
 *
 * 1. Laravel's env repository stacks three adapters — `$_SERVER`, `$_ENV`, and
 *    `getenv()` (putenv is enabled by default) — so all three must move together
 *    or whichever stale one is read first wins.
 * 2. Restoring matters more than setting. PHPUnit's `<env>` entries do not
 *    necessarily populate `$_SERVER`, so a naive `$previous = $_SERVER[$key]`
 *    captures null and "restores" APP_ENV to an empty string. Every later test
 *    in that process then runs with no environment, failing in ways that look
 *    nothing like an env problem (mismatched CSRF tokens, for instance).
 *
 * @param  array<string, string|null>  $overrides  Null removes the variable.
 * @return array<string, mixed>
 */
function configFileWithEnv(string $configFile, array $overrides): array
{
    // Writes go to $_ENV/$_SERVER/putenv directly. Env::get() is a *read* through
    // an immutable repository — `unset(Env::get($key))` is not valid PHP ("cannot
    // use method return value in write context") and takes the whole suite down
    // before a single test boots. Do not reintroduce it.
    $write = static function (string $key, ?string $value): void {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    };

    $previous = [];

    foreach ($overrides as $key => $value) {
        // Read back through Env so the captured value is whatever config would
        // have seen, across all three adapters.
        $existing = Env::get($key);
        $previous[$key] = $existing === null || $existing === false ? null : (string) $existing;
        $write($key, $value);
    }

    try {
        return require base_path("config/{$configFile}.php");
    } finally {
        foreach ($previous as $key => $value) {
            $write($key, $value);
        }
    }
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
