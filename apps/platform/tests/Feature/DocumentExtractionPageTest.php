<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Actions\ProvisionTenantAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

pest()
    ->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('staff can open the OCR extraction page for an uploaded document', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Payslip',
        'status' => DocumentRequestStatus::Submitted,
    ]);
    $uploaded = UploadedDocument::factory()->processed(
        json_encode([
            'document_type' => 'payslip',
            'summary' => 'January payslip',
            'fields' => [
                [
                    'label' => 'BSN',
                    'value' => '123456789',
                    'confidence' => 0.97,
                    'sensitivity' => 'bsn',
                ],
            ],
            'tables' => [
                [
                    'title' => 'Earnings',
                    'headers' => ['Code', 'Amount'],
                    'rows' => [['1000', '2500']],
                ],
            ],
            'notes' => [],
            'confidence' => 0.94,
        ], JSON_THROW_ON_ERROR),
    )->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'original_filename' => 'payslip.pdf',
        'processing_status' => DocumentProcessingStatus::Completed,
    ]);

    $this->actingAs($owner)
        ->withSession([
            'active_tenant_id' => $tenant->id,
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ])
        ->get(workspaceRoute('workspaces.uploaded-documents.show', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('uploaded-documents/show')
            ->where('uploaded_document.original_filename', 'payslip.pdf')
            ->where('uploaded_document.processing_status', DocumentProcessingStatus::Completed->value)
            ->where('uploaded_document.extraction.document_type', 'payslip')
            ->where('uploaded_document.extraction.confidence', 0.94)
            ->where('uploaded_document.extraction.fields.0.label', 'BSN')
            ->where('uploaded_document.extraction.fields.0.value', '123456789')
            ->where('uploaded_document.extraction.fields.0.confidence', 0.97)
            ->where('uploaded_document.extraction.fields.0.sensitivity', 'bsn')
            ->where('dossier.id', $dossier->id)
            ->where('document_request.title', 'Payslip')
        );
});

test('staff from another tenant cannot open an extraction page', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $tenantA = resolve(ProvisionTenantAction::class)->handle('Firm A', $ownerA);
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Firm B', $ownerB);

    $tenantA->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenantA->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenantA->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenantA->id,
        'dossier_id' => $dossier->id,
    ]);
    $uploaded = UploadedDocument::factory()->processed()->create([
        'tenant_id' => $tenantA->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $this->actingAs($ownerB)
        ->withSession([
            'active_tenant_id' => $tenantB->id,
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ])
        ->get(workspaceRoute('workspaces.uploaded-documents.show', $tenantA, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertNotFound();
});

test('staff must recently confirm their password before opening an extraction page', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $uploaded = UploadedDocument::factory()->processed()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.uploaded-documents.show', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertRedirect(route('password.confirm'));
});

test('guest cannot open the OCR extraction page', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $uploaded = UploadedDocument::factory()->processed()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $this->get(workspaceRoute('workspaces.uploaded-documents.show', $tenant, [
        'uploadedDocument' => $uploaded,
    ]))->assertRedirect();
});
