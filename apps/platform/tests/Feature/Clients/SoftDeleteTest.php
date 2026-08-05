<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @return array{owner: User, tenant: mixed, client: Client, dossier: Dossier}
 */
function softDeleteContext(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts 2025',
    ]);

    return ['owner' => $owner, 'tenant' => $tenant, 'client' => $client, 'dossier' => $dossier];
}

test('owner can archive a dossier and it disappears from the index', function (): void {
    $context = softDeleteContext();

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.dossiers.destroy', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]))
        ->assertRedirect(workspaceRoute('workspaces.dossiers.index', $context['tenant']));

    expect($context['dossier']->fresh()->trashed())->toBeTrue();

    $this->get(workspaceRoute('workspaces.dossiers.index', $context['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossiers.data', 0)
            ->where('dossiers.total', 0));

    $this->get(workspaceRoute('workspaces.dossiers.show', $context['tenant'], [
        'dossier' => $context['dossier'],
    ]))->assertNotFound();
});

test('archiving a dossier logs a tenant scoped audit entry', function (): void {
    $context = softDeleteContext();

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.dossiers.destroy', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]));

    $activity = Activity::query()
        ->where('event', AuditEvent::DossierDeleted->value)
        ->where('subject_id', $context['dossier']->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->tenant_id)->toBe($context['tenant']->id)
        ->and($activity->causer_id)->toBe($context['owner']->id)
        ->and($activity->getProperty('title'))->toBe('Annual accounts 2025');
});

test('archiving a dossier revokes active portal grants', function (): void {
    $context = softDeleteContext();

    $result = resolve(CreateClientAccessGrantAction::class)->handle(
        $context['dossier'],
        $context['owner'],
    );
    $grant = $result['grant'];

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.dossiers.destroy', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]));

    expect($grant->fresh()->revoked_at)->not->toBeNull();
});

test('owner can archive a client and cascade soft delete to dossiers', function (): void {
    $context = softDeleteContext();

    $secondDossier = Dossier::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'client_id' => $context['client']->id,
        'title' => 'VAT 2025',
        'status' => DossierStatus::Completed,
    ]);

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.clients.destroy', $context['tenant'], [
            'client' => $context['client'],
        ]))
        ->assertRedirect(workspaceRoute('workspaces.clients.index', $context['tenant']));

    expect($context['client']->fresh()->trashed())->toBeTrue()
        ->and($context['dossier']->fresh()->trashed())->toBeTrue()
        ->and($secondDossier->fresh()->trashed())->toBeTrue();

    $this->get(workspaceRoute('workspaces.clients.index', $context['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('clients.data', 0)
            ->where('clients.total', 0));
});

test('archiving a client logs audit entries for the client and each dossier', function (): void {
    $context = softDeleteContext();

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.clients.destroy', $context['tenant'], [
            'client' => $context['client'],
        ]));

    expect(Activity::query()->where('event', AuditEvent::ClientDeleted->value)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', AuditEvent::DossierDeleted->value)->exists())->toBeTrue();
});

test('users outside the tenant cannot archive a dossier', function (): void {
    $context = softDeleteContext();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.dossiers.destroy', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]))
        ->assertNotFound();

    expect($context['dossier']->fresh()->trashed())->toBeFalse();
});

test('read-only members cannot archive clients', function (): void {
    $context = softDeleteContext();
    $reader = workspaceMember($context['tenant'], Role::ReadOnly);

    $this->actingAs($reader)
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.clients.destroy', $context['tenant'], [
            'client' => $context['client'],
        ]))
        ->assertForbidden();

    expect($context['client']->fresh()->trashed())->toBeFalse();
});

test('archived dossier subjects remain resolvable in the activity log', function (): void {
    $context = softDeleteContext();

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.dossiers.destroy', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]));

    $activity = Activity::query()
        ->where('event', AuditEvent::DossierDeleted->value)
        ->firstOrFail();

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->get(workspaceRoute('workspaces.activity.index', $context['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('activities.data.0.id', $activity->id)
            ->where('activities.data.0.subject.name', 'Annual accounts 2025'));
});
