<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\Access\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('owner can view a client record with active and archived dossier context', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Client',
        'email' => 'jane@example.com',
    ]);
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Active dossier',
    ]);
    $archivedDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Archived dossier',
    ]);
    $archivedDossier->delete();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.show', $tenant, ['client' => $client]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/clients/show')
            ->where('client.name', 'Jane Client')
            ->where('client.active_dossiers_count', 1)
            ->where('client.archived_dossiers_count', 1)
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.title', 'Active dossier'));
});

test('owner can open the client and dossier edit screens', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Client',
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts',
        'reference' => 'AA-2026',
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.edit', $tenant, ['client' => $client]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/clients/edit')
            ->where('client.name', 'Jane Client')
            ->where('client.email', 'jane@example.com'));

    $this->get(workspaceRoute('workspaces.dossiers.edit', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dossiers/edit')
            ->where('dossier.title', 'Annual accounts')
            ->where('dossier.reference', 'AA-2026')
            ->where('dossier.client.name', 'Jane Client'));
});

test('owner can update client details and the change is audited', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Client',
        'email' => 'jane@example.com',
    ]);

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.clients.update', $tenant, ['client' => $client]), [
            'name' => 'Jane Updated',
            'email' => 'jane.updated@example.com',
        ])
        ->assertRedirect(workspaceRoute('workspaces.clients.show', $tenant, ['client' => $client]));

    expect($client->fresh())
        ->name->toBe('Jane Updated')
        ->email->toBe('jane.updated@example.com');

    $activity = Activity::query()
        ->where('subject_type', (new Client)->getMorphClass())
        ->where('subject_id', $client->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($activity->attribute_changes?->get('attributes'))
        ->toMatchArray([
            'name' => 'Jane Updated',
            'email' => 'jane.updated@example.com',
        ]);
});

test('client updates keep email addresses unique within the workspace', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $otherClient = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'used@example.com',
    ]);

    $this->actingAs($owner)
        ->from(workspaceRoute('workspaces.clients.edit', $tenant, ['client' => $client]))
        ->patch(workspaceRoute('workspaces.clients.update', $tenant, ['client' => $client]), [
            'name' => $client->name,
            'email' => $otherClient->email,
        ])
        ->assertSessionHasErrors('email');

    expect($client->fresh()->email)->toBe($client->email);
});

test('owner can update an open dossier but not a completed dossier', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Old title',
        'reference' => 'OLD-1',
    ]);

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.dossiers.update', $tenant, ['dossier' => $dossier]), [
            'title' => 'Annual accounts 2026',
            'reference' => 'AA-2026',
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]));

    expect($dossier->fresh())
        ->title->toBe('Annual accounts 2026')
        ->reference->toBe('AA-2026');

    $dossier->forceFill(['status' => DossierStatus::Completed])->save();

    $this->patch(workspaceRoute('workspaces.dossiers.update', $tenant, ['dossier' => $dossier]), [
        'title' => 'Should not change',
        'reference' => 'BLOCKED',
    ])->assertForbidden();

    expect($dossier->fresh()->title)->toBe('Annual accounts 2026');
});

test('archived indexes separate retained records from active work', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Archived Jane',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Retained annual accounts',
    ]);
    $dossier->delete();
    $client->delete();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.archived', $tenant).'?filter[q]=Archived')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/clients/archived')
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'Archived Jane'));

    $this->get(workspaceRoute('workspaces.dossiers.archived', $tenant).'?filter[q]=annual')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dossiers/archived')
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.client_archived', true));

    // Scout also matches archived dossiers by related client name.
    $this->get(workspaceRoute('workspaces.dossiers.archived', $tenant).'?filter[q]=Jane')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dossiers/archived')
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.client_name', 'Archived Jane'));
});

test('restoring a client leaves its dossiers archived and records the action', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $dossier->delete();
    $client->delete();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.clients.restore', $tenant, ['client' => $client]))
        ->assertRedirect(workspaceRoute('workspaces.clients.show', $tenant, ['client' => $client]));

    expect($client->fresh()->trashed())->toBeFalse()
        ->and($dossier->fresh()->trashed())->toBeTrue()
        ->and(Activity::query()
            ->where('event', AuditEvent::ClientRestored->value)
            ->where('subject_id', $client->id)
            ->where('causer_id', $owner->id)
            ->exists())->toBeTrue();
});

test('dossier restore requires an active client and keeps portal access revoked', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $grant = resolve(CreateClientAccessGrantAction::class)
        ->handle($dossier, $owner, 7)['grant'];

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.clients.destroy', $tenant, ['client' => $client]));

    $this->from(workspaceRoute('workspaces.dossiers.archived', $tenant))
        ->patch(workspaceRoute('workspaces.dossiers.restore', $tenant, ['dossier' => $dossier]))
        ->assertSessionHasErrors('dossier');

    expect($dossier->fresh()->trashed())->toBeTrue();

    $this->patch(workspaceRoute('workspaces.clients.restore', $tenant, ['client' => $client]));
    $this->patch(workspaceRoute('workspaces.dossiers.restore', $tenant, ['dossier' => $dossier]))
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]));

    expect($dossier->fresh()->trashed())->toBeFalse()
        ->and($grant->fresh()->revoked_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('event', AuditEvent::DossierRestored->value)
            ->where('subject_id', $dossier->id)
            ->exists())->toBeTrue();
});

test('read only members can inspect the dossier archive but cannot restore records', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $dossier->delete();

    $this->actingAs($reader)
        ->get(workspaceRoute('workspaces.dossiers.archived', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('can_restore', false)
            ->where('can_restore_clients', false)
            ->has('dossiers.data', 1));

    $this->patch(workspaceRoute('workspaces.dossiers.restore', $tenant, ['dossier' => $dossier]))
        ->assertForbidden();

    expect($dossier->fresh()->trashed())->toBeTrue();
});

test('archived records cannot be restored through another workspace', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $client->delete();
    ['owner' => $otherOwner, 'tenant' => $otherTenant] = provisionWorkspace();

    $this->actingAs($otherOwner)
        ->patch(workspaceRoute('workspaces.clients.restore', $otherTenant, ['client' => $client]))
        ->assertNotFound();

    actingInWorkspace($tenant);
    expect($client->fresh()->trashed())->toBeTrue();
});
