<?php

declare(strict_types=1);

use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    config()->set('filesystems.default', 'local');
});

/**
 * Portal destroy is authorized by grant middleware + controller aborts (not Gate).
 * MultiFileUploadTest covers the happy path and under-review denial; these cases
 * cover cross-dossier / cross-tenant isolation for the coverage map entry.
 */
test('portal client cannot destroy an upload that belongs to another dossier', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $grantedDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $grantResult = resolve(CreateClientAccessGrantAction::class)->handle(
        $grantedDossier,
        $owner,
        7,
    );

    $otherRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $otherDossier->id,
        'status' => DocumentRequestStatus::Submitted,
        'configuration' => ['is_required' => true, 'allow_multiple' => true],
    ]);
    $foreignUpload = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $otherRequest->id,
    ]);

    $this->get(route('portal.exchange', $grantResult['plain_text_token']));

    $this->delete(route('portal.document-requests.uploaded-documents.destroy', [
        'documentRequest' => $otherRequest->id,
        'uploadedDocument' => $foreignUpload->id,
    ]))->assertNotFound();

    actingInWorkspace($tenant);

    expect(UploadedDocument::query()->whereKey($foreignUpload->id)->exists())->toBeTrue();
});

test('portal client cannot destroy an upload that belongs to another tenant', function (): void {
    ['owner' => $owner, 'tenant' => $homeTenant] = provisionWorkspace();
    ['tenant' => $otherTenant] = provisionWorkspace('Other firm');

    // Second provisionWorkspace left Other firm current — switch back before home fixtures.
    actingInWorkspace($homeTenant);

    $homeClient = Client::factory()->create(['tenant_id' => $homeTenant->id]);
    $homeDossier = Dossier::factory()->create([
        'tenant_id' => $homeTenant->id,
        'client_id' => $homeClient->id,
    ]);

    $homeGrant = resolve(CreateClientAccessGrantAction::class)->handle(
        $homeDossier,
        $owner,
        7,
    );

    actingInWorkspace($otherTenant);

    $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $otherTenant->id,
        'client_id' => $otherClient->id,
    ]);

    $foreignRequest = DocumentRequest::factory()->create([
        'tenant_id' => $otherTenant->id,
        'dossier_id' => $otherDossier->id,
        'status' => DocumentRequestStatus::Submitted,
        'configuration' => ['is_required' => true, 'allow_multiple' => true],
    ]);
    $foreignUpload = UploadedDocument::factory()->create([
        'tenant_id' => $otherTenant->id,
        'document_request_id' => $foreignRequest->id,
    ]);

    $this->get(route('portal.exchange', $homeGrant['plain_text_token']));

    $this->delete(route('portal.document-requests.uploaded-documents.destroy', [
        'documentRequest' => $foreignRequest->id,
        'uploadedDocument' => $foreignUpload->id,
    ]))->assertNotFound();

    actingInWorkspace($otherTenant);

    expect(UploadedDocument::query()->whereKey($foreignUpload->id)->exists())->toBeTrue();
});
