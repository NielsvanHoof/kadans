<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Ai\DossierReminderDraftAgent;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use Laravel\Ai\Prompts\AgentPrompt;

test('staff can generate a reminder draft mentioning outstanding items', function (): void {
    DossierReminderDraftAgent::fake(['Hi Jane, just checking in on your outstanding documents.']);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Bank statement',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.dossiers.reminders.draft', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertJson(['message' => 'Hi Jane, just checking in on your outstanding documents.']);

    DossierReminderDraftAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('Bank statement') && $prompt->contains('Jane Doe'),
    );
});

test('draft endpoint rejects a dossier with no outstanding items', function (): void {
    DossierReminderDraftAgent::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.dossiers.reminders.draft', $tenant, ['dossier' => $dossier]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dossier');

    DossierReminderDraftAgent::assertNeverPrompted();
});

test('draft endpoint is forbidden for a completed dossier', function (): void {
    DossierReminderDraftAgent::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Completed,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.dossiers.reminders.draft', $tenant, ['dossier' => $dossier]))
        ->assertForbidden();

    DossierReminderDraftAgent::assertNeverPrompted();
});

test('read only members cannot generate reminder drafts', function (): void {
    DossierReminderDraftAgent::fake();

    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->actingAs($reader)
        ->postJson(workspaceRoute('workspaces.dossiers.reminders.draft', $tenant, ['dossier' => $dossier]))
        ->assertForbidden();

    DossierReminderDraftAgent::assertNeverPrompted();
});

test('reminder draft endpoint is rate limited', function (): void {
    DossierReminderDraftAgent::fake(['Draft message.']);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->actingAs($owner);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson(workspaceRoute('workspaces.dossiers.reminders.draft', $tenant, ['dossier' => $dossier]))
            ->assertOk();
    }

    $this->postJson(workspaceRoute('workspaces.dossiers.reminders.draft', $tenant, ['dossier' => $dossier]))
        ->assertTooManyRequests();
});
