<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Platform\Http\Middleware\HandleInertiaRequests;
use App\Tenancy\Actions\ProvisionTenantAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Support\Header;
use Spatie\Activitylog\Enums\ActivityEvent;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('viewing a dossier writes a tenant scoped audit entry', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts 2025',
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk();

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe(AuditEvent::DossierViewed->value)
        ->and($activity->tenant_id)->toBe($tenant->id)
        ->and($activity->causer_id)->toBe($owner->id)
        ->and($activity->subject_id)->toBe($dossier->id)
        ->and($activity->getProperty('route'))->toBe('workspaces.dossiers.show');
});

test('partially reloading a dossier does not write another view audit entry', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $url = workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get($url)
        ->assertOk();

    $this->withHeaders([
        Header::INERTIA => 'true',
        // Inertia returns 409 when the asset version header is missing/mismatched.
        Header::VERSION => resolve(HandleInertiaRequests::class)->version(request()) ?? '',
        Header::PARTIAL_COMPONENT => 'dossiers/show',
        Header::PARTIAL_ONLY => 'dossier',
    ])->get($url)->assertOk();

    expect(Activity::query()
        ->where('event', AuditEvent::DossierViewed->value)
        ->where('subject_id', $dossier->id)
        ->count())->toBe(1);
});

test('creating a dossier logs attribute changes for the tenant', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $this->actingAs($owner);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payslip archive',
        'reference' => 'PS-2025-01',
    ]);

    $activity = Activity::query()
        ->forEvent(ActivityEvent::Created)
        ->forSubject($dossier)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->tenant_id)->toBe($tenant->id)
        ->and($activity->causer_id)->toBe($owner->id)
        ->and($activity->attribute_changes?->get('attributes'))->toMatchArray([
            'title' => 'Payslip archive',
            'reference' => 'PS-2025-01',
        ]);
});

test('audit log records cannot be updated', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $activity = resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierViewed,
        $dossier,
        causer: $owner,
    );

    expect(fn () => $activity->update(['description' => 'tampered']))
        ->toThrow(RuntimeException::class, 'Audit log records are immutable.');
});

test('audit properties discard secrets and truncate untrusted request metadata', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    request()->headers->set('User-Agent', str_repeat('x', 700));

    $activity = resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierViewed,
        $dossier,
        [
            'safe' => 'kept',
            'plain_text_token' => 'secret-token',
            'nested' => [
                'portal_url' => 'https://example.test/portal/secret-token',
                'channel' => 'mail',
            ],
        ],
        $owner,
    );

    expect($activity->getProperty('safe'))->toBe('kept')
        ->and($activity->getProperty('plain_text_token'))->toBeNull()
        ->and($activity->getProperty('nested'))->toBe(['channel' => 'mail'])
        ->and((string) $activity->getProperty('user_agent'))->toHaveLength(512)
        ->and((string) json_encode($activity->properties))->not->toContain('secret-token');
});
