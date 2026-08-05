<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('staff can view the media library with pending and uploaded documents', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll 2025',
    ]);

    $pending = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Pending payslip',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $uploadedRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Uploaded ID',
        'status' => DocumentRequestStatus::Submitted,
    ]);

    UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $uploadedRequest->id,
        'original_filename' => 'passport.pdf',
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.media.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/media/index')
            ->where('can_download', true)
            ->has('documents.data', 2)
            ->where('documents.total', 2)
            ->has('indexQuery')
            ->where('documents.data.0.title', fn (string $title): bool => in_array($title, [
                $pending->title,
                $uploadedRequest->title,
            ], true))
            ->where('documents.data', fn ($documents) => collect($documents)->contains(
                fn (array $document): bool => $document['title'] === 'Pending payslip'
                    && $document['status'] === 'pending'
                    && $document['uploaded_documents'] === []
                    && $document['client_name'] === $client->name
                    && $document['dossier']['id'] === $dossier->id,
            ))
            ->where('documents.data', fn ($documents) => collect($documents)->contains(
                fn (array $document): bool => $document['title'] === 'Uploaded ID'
                    && $document['status'] === 'submitted'
                    && $document['uploaded_documents'][0]['original_filename'] === 'passport.pdf',
            )));
});

test('media library excludes document requests from archived dossiers', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $activeDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Active payroll',
    ]);
    $archivedDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Archived payroll',
    ]);

    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $activeDossier->id,
        'title' => 'Active payslip',
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $archivedDossier->id,
        'title' => 'Archived payslip',
    ]);

    // Soft-delete mimics archive; media library must not crash or list these rows.
    $archivedDossier->delete();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.media.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/media/index')
            ->has('documents.data', 1)
            ->where('documents.total', 1)
            ->where('documents.data.0.title', 'Active payslip'));
});

test('media library does not include document requests from another tenant', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Tenant A');
    ['tenant' => $tenantB] = provisionWorkspace('Tenant B');

    $clientB = Client::factory()->create(['tenant_id' => $tenantB->id]);
    $dossierB = Dossier::factory()->create([
        'tenant_id' => $tenantB->id,
        'client_id' => $clientB->id,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenantB->id,
        'dossier_id' => $dossierB->id,
        'title' => 'Foreign request',
    ]);

    actingInWorkspace($tenantA);
    $clientA = Client::factory()->create(['tenant_id' => $tenantA->id]);
    $dossierA = Dossier::factory()->create([
        'tenant_id' => $tenantA->id,
        'client_id' => $clientA->id,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenantA->id,
        'dossier_id' => $dossierA->id,
        'title' => 'Own request',
    ]);

    $this->actingAs($ownerA)
        ->get(workspaceRoute('workspaces.media.index', $tenantA))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/media/index')
            ->has('documents.data', 1)
            ->where('documents.total', 1)
            ->where('documents.data.0.title', 'Own request'));
});

test('reviewer can browse the media library but cannot download', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reviewer = workspaceMember($tenant, Role::Reviewer);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);
    $uploaded = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $this->actingAs($reviewer)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.media.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/media/index')
            ->where('can_download', false)
            ->has('documents.data', 1)
            ->where('documents.total', 1));

    $this->get(workspaceRoute(
        'workspaces.uploaded-documents.download',
        $tenant,
        ['uploadedDocument' => $uploaded],
    ))
        ->assertForbidden();
});

test('guests cannot view the media library', function (): void {
    $this->get(workspaceRoute('workspaces.media.index', 'acme-accountants'))
        ->assertRedirect(route('login'));
});
