<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use App\Accounts\Models\User;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Actions\ProvisionTenantAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()
    ->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('staff can create the phase one document collection graph', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Client',
        'email' => 'jane@example.com',
    ]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => '2025 Payroll dossier',
        'status' => DossierStatus::AwaitingClient,
    ]);

    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Payslip January 2025',
        'instructions' => 'Upload a PDF payslip.',
    ]);

    $uploadedDocument = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'original_filename' => 'payslip-jan.pdf',
    ]);

    $grant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $owner->id,
    ]);

    $documentRequest->update(['status' => DocumentRequestStatus::Submitted]);

    expect($client->dossiers)->toHaveCount(1)
        ->and($dossier->client->is($client))->toBeTrue()
        ->and($dossier->documentRequests)->toHaveCount(1)
        ->and($documentRequest->uploadedDocument->is($uploadedDocument))->toBeTrue()
        ->and($dossier->clientAccessGrants)->toHaveCount(1)
        ->and($grant->isValid())->toBeTrue()
        ->and($grant->dossier->is($dossier))->toBeTrue();
});

test('tenant owned records cannot be queried without an active tenant', function (): void {
    expect(fn () => Client::query()->count())
        ->toThrow(RuntimeException::class, 'Cannot query tenant-owned records without an active tenant.');
});

test('phase one models are isolated per tenant', function (): void {
    $owner = User::factory()->create();
    $tenantA = resolve(ProvisionTenantAction::class)->handle('Tenant A', $owner);
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Tenant B', $owner);

    $tenantA->makeCurrent();

    $clientA = Client::factory()->create(['tenant_id' => $tenantA->id]);
    $dossierA = Dossier::factory()->create([
        'tenant_id' => $tenantA->id,
        'client_id' => $clientA->id,
    ]);

    $tenantB->makeCurrent();

    expect(Client::query()->whereKey($clientA->id)->exists())->toBeFalse()
        ->and(Dossier::query()->whereKey($dossierA->id)->exists())->toBeFalse();
});

test('composite tenant foreign keys reject cross tenant dossier links', function (): void {
    $owner = User::factory()->create();
    $tenantA = resolve(ProvisionTenantAction::class)->handle('Tenant A', $owner);
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Tenant B', $owner);

    $tenantA->makeCurrent();
    $clientA = Client::factory()->create(['tenant_id' => $tenantA->id]);
    $dossierA = Dossier::factory()->create([
        'tenant_id' => $tenantA->id,
        'client_id' => $clientA->id,
    ]);

    $tenantB->makeCurrent();
    $clientB = Client::factory()->create(['tenant_id' => $tenantB->id]);
    $dossierB = Dossier::factory()->create([
        'tenant_id' => $tenantB->id,
        'client_id' => $clientB->id,
    ]);

    expect(fn () => DocumentRequest::factory()->create([
        'tenant_id' => $tenantB->id,
        'dossier_id' => $dossierA->id,
        'title' => 'Invalid request',
    ]))->toThrow(QueryException::class);
});

test('client access grant validity reflects expiry and revocation', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $expiredGrant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $owner->id,
        'expires_at' => now()->subDay(),
    ]);

    $revokedGrant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $owner->id,
        'revoked_at' => now(),
    ]);

    expect($expiredGrant->isValid())->toBeFalse()
        ->and($revokedGrant->isValid())->toBeFalse();
});
