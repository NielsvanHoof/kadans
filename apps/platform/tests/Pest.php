<?php

declare(strict_types=1);

use App\Accounts\Actions\Profile\CreateNewUser;
use App\Accounts\Actions\Security\ResetUserPassword;
use App\Accounts\Models\User;
use App\Audit\Actions\LogTenantActivityAction;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\Access\CreateClientAccessGrantAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\Uploads\StagedUpload;
use App\Dossiers\Support\Uploads\UploadTicket;
use App\Platform\Http\Middleware\HandleInertiaRequests;
use App\Platform\Jobs\DataSubject\GenerateDataSubjectExportJob;
use App\Reporting\Queries\ActivityLog\ActivityLogRowMapper;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Queries\PaginatedIndexQuery;
use App\Tenancy\Actions\Provisioning\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Support\Header;
use Laravel\Ai\Embeddings;
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
    ->beforeEach(function (): void {
        // UploadedDocument::searchable() embeds overview text. PHPUnit uses
        // the collection driver, but toSearchableEmbedding() still runs.
        Embeddings::fake();
    })
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

    // Layer-first domains (`App\Clients\Actions`) and Dossiers
    // layer-then-concern (`App\Dossiers\Actions\Lifecycle`) must both match.
    expect('App\*\Actions')
        ->classes()
        ->toBeFinal(),
    expect('App\*\Actions\*')
        ->classes()
        ->toBeFinal(),

    // Domain actions end in Action; Fortify Accounts contracts keep framework names.
    expect('App\*\Actions')
        ->classes()
        ->toHaveSuffix('Action')
        ->ignoring([
            'App\Accounts\Actions',
        ]),
    expect('App\*\Actions\*')
        ->classes()
        ->toHaveSuffix('Action')
        ->ignoring([
            CreateNewUser::class,
            ResetUserPassword::class,
        ]),

    expect('App\*\Actions')
        ->classes()
        ->toHaveMethod('handle')
        ->ignoring([
            'App\Accounts\Actions',
            LogTenantActivityAction::class,
        ]),
    expect('App\*\Actions\*')
        ->classes()
        ->toHaveMethod('handle')
        ->ignoring([
            CreateNewUser::class,
            ResetUserPassword::class,
        ]),

    expect('App\*\Actions')
        ->classes()
        ->not->toHaveMethod('__invoke')
        ->ignoring([
            'App\Accounts\Actions',
            LogTenantActivityAction::class,
        ]),
    expect('App\*\Actions\*')
        ->classes()
        ->not->toHaveMethod('__invoke')
        ->ignoring([
            CreateNewUser::class,
            ResetUserPassword::class,
        ]),

    // Read models end in Query; Spatie filters and row mappers are not Queries.
    expect('App\*\Queries')
        ->classes()
        ->toHaveSuffix('Query')
        ->ignoring([
            'App\Shared\Queries\Filters',
            ActivityLogRowMapper::class,
        ]),
    expect('App\*\Queries\*')
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
    expect('App\*\Queries\*')
        ->classes()
        ->toHaveMethod('handle')
        ->ignoring([
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
    expect('App\*\Queries\*')
        ->classes()
        ->not->toHaveMethod('__invoke')
        ->ignoring([
            'App\Shared\Queries\Filters',
            ActivityLogRowMapper::class,
        ]),

    expect('App\*\Queries')
        ->classes()
        ->toBeFinal()
        ->ignoring([
            PaginatedIndexQuery::class,
        ]),
    expect('App\*\Queries\*')
        ->classes()
        ->toBeFinal()
        ->ignoring([
            'App\Shared\Queries\Filters',
            ActivityLogRowMapper::class,
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
    expect('App\*\Http\Controllers\*')
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
    |   L1  Accounts, Integrations
    |   L2  Tenancy
    |   L3  Questionnaires
    |   L4  Clients, Dossiers, DocumentProcessing, AudioProcessing, Pipelines
    |   L5  ClientPortal
    |   L6  Reporting, Platform, WorkspaceNotifications
    |
    |   Cross-cutting: Audit — any domain may log activity; Audit itself may
    |   only reach Accounts and Tenancy (enforced separately below).
    |
    | Integrations is an L1 sibling of Accounts (OAuth connections / Socialite).
    | Login needs the token vault; calendar product UI stays higher and calls
    | Integrations. Accounts and User may reach Integrations; Shared must not.
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
    | tracking), registering a PipelineRunner into Pipelines' registry rather
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
            'App\Integrations',
            'App\Pipelines',
            'App\WorkspaceNotifications',
            'App\Platform',
            'App\Questionnaires',
            'App\Reporting',
            'App\Tenancy',
        ]),

    // User is the cross-domain hub, so it is ignored here and constrained
    // separately below. Audit is cross-cutting and deliberately absent.
    // Integrations is an L1 sibling — Accounts may use the OAuth token vault.
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
    // Integrations is L1 sibling — User::oauthConnections() may import it.
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

    // OAuth connections / Socialite. May reach Accounts (User) and Shared;
    // must not climb into Tenancy or anything above.
    expect('App\Integrations')
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

    // Registers a PipelineRunner into Pipelines' registry; otherwise
    // unremarkable as an L4 sibling.
    expect('App\AudioProcessing')
        ->not->toUse([
            'App\ClientPortal',
            'App\Platform',
            'App\Reporting',
            'App\WorkspaceNotifications',
        ]),

    // Depends on Dossiers only for PipelinePolicy::download's DossierPermission
    // check; OCR/transcription specifics stay inverted behind PipelineRunner.
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

    $membership = new TenantMembership;
    $membership->forceFill([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ])->saveOrFail();

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
 * Headers for the follow-up request that resolves an Inertia deferred prop.
 *
 * Deferred props are absent from the first response by design, so asserting on
 * one means replaying the request the client makes after paint. A plain
 * `?only[]=` query string does not do this — Inertia reads the partial headers.
 *
 * @return array<string, string>
 */
function deferredPropHeaders(string $prop, string $component = 'workspaces/dashboard'): array
{
    return [
        Header::INERTIA => 'true',
        // Inertia answers 409 when the asset version header is missing or stale.
        Header::VERSION => resolve(HandleInertiaRequests::class)->version(request()) ?? '',
        Header::PARTIAL_COMPONENT => $component,
        Header::PARTIAL_ONLY => $prop,
    ];
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

/**
 * A workspace with a client, a draft dossier, and a live portal access grant.
 *
 * Shared because the portal is exercised from several angles — session
 * lifetime, the ended page, uploads, downloads — and each of those needs the
 * same four rows plus the one-time plaintext token that only exists in the
 * action's return value.
 *
 * @return array{owner: User, tenant: Tenant, client: Client, dossier: Dossier, plainTextToken: string, grant: ClientAccessGrant}
 */
function createPortalDossierWithGrant(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => '2025 Payroll dossier',
        'status' => DossierStatus::Draft,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'client' => $client,
        'dossier' => $dossier->fresh(),
        'plainTextToken' => $result['plain_text_token'],
        'grant' => $result['grant'],
    ];
}

/**
 * Stage a file in object storage the way a presigned browser upload would, and
 * return the sealed ticket that redeems it.
 *
 * Uploads no longer travel through PHP, so `UploadedFile::fake()` has nothing
 * to attach to: by the time a request reaches the application the bytes are
 * already in the bucket. This helper reproduces that state — object in the
 * staging prefix, sealed ticket in hand — which is what every upload endpoint
 * now expects.
 *
 * It seals through the real UploadTicket so the format under test is the
 * format in production; a hand-rolled payload here could drift while every
 * test kept passing.
 *
 * Callers must have faked the disk first (`Storage::fake('s3')`).
 */
function stagedUploadTicket(
    int $tenantId,
    string $filename = 'statement.pdf',
    UploadPurpose $purpose = UploadPurpose::Document,
    int $sizeBytes = 1024,
    ?string $disk = null,
): string {
    $disk ??= Config::string('filesystems.default');
    $path = stagedUploadPath($tenantId, $filename, $sizeBytes, $disk);

    return new UploadTicket(
        purpose: $purpose,
        tenantId: $tenantId,
        disk: $disk,
        path: $path,
        filename: $filename,
        expiresAt: now()->addMinutes(30),
    )->seal();
}

/**
 * The verified StagedUpload an action receives once a ticket has been claimed.
 * For tests that exercise the upload actions directly rather than over HTTP.
 */
function stagedUpload(
    int $tenantId,
    string $filename = 'statement.pdf',
    string $mimeType = 'application/pdf',
    int $sizeBytes = 1024,
    ?string $disk = null,
): StagedUpload {
    $disk ??= Config::string('filesystems.default');
    $path = stagedUploadPath($tenantId, $filename, $sizeBytes, $disk);

    return new StagedUpload(
        disk: $disk,
        path: $path,
        originalFilename: $filename,
        mimeType: $mimeType,
        sizeBytes: $sizeBytes,
    );
}

/** Write the staged object and return its key. Shared by both helpers above. */
function stagedUploadPath(int $tenantId, string $filename, int $sizeBytes, string $disk): string
{
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $path = sprintf('staging/tenants/%d/%s.%s', $tenantId, (string) Str::ulid(), $extension);

    Storage::disk($disk)->put($path, str_repeat('x', max($sizeBytes, 0)));

    return $path;
}

/**
 * The stale-claim cutoff GenerateDataSubjectExportJob passes in production.
 *
 * A fulfilment claim older than the job's own worst-case lifecycle belonged to
 * a worker that is certainly gone. Tests calling the action directly must pass
 * the same value, or they are not exercising the claim the job actually takes.
 */
function dataSubjectExportStaleCutoff(): CarbonInterface
{
    return Date::now()->subSeconds(GenerateDataSubjectExportJob::lifecycleSeconds());
}
