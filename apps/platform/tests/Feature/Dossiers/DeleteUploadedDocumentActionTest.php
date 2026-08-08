<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Actions\DocumentRequests\DeleteUploadedDocumentAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

/**
 * @return array{tenant: Tenant, documentRequest: DocumentRequest, uploadedDocument: UploadedDocument}
 */
function createDeleteUploadedDocumentFixture(
    DocumentRequestStatus $status = DocumentRequestStatus::Submitted,
): array {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => $status,
        'answered_at' => $status === DocumentRequestStatus::Submitted ? now() : null,
        'configuration' => ['is_required' => true, 'allow_multiple' => true],
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/file.pdf';
    Storage::disk('local')->put($path, 'fake-pdf-bytes');

    $uploadedDocument = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => $path,
    ]);

    return [
        'tenant' => $tenant,
        'documentRequest' => $documentRequest,
        'uploadedDocument' => $uploadedDocument,
    ];
}

test('deletes the row and storage file, reopening the request when it was the last upload', function (): void {
    $fixture = createDeleteUploadedDocumentFixture();

    resolve(DeleteUploadedDocumentAction::class)->handle($fixture['uploadedDocument']);

    expect(UploadedDocument::query()->whereKey($fixture['uploadedDocument']->id)->exists())->toBeFalse()
        ->and($fixture['documentRequest']->fresh()->status)->toBe(DocumentRequestStatus::Pending)
        ->and($fixture['documentRequest']->fresh()->answered_at)->toBeNull();

    Storage::disk('local')->assertMissing($fixture['uploadedDocument']->path);
});

test('rolls back database work and keeps the storage object when a mid-delete failure occurs', function (): void {
    $fixture = createDeleteUploadedDocumentFixture();
    $path = $fixture['uploadedDocument']->path;

    // Force a failure inside the transaction without mocking final action classes.
    UploadedDocument::deleting(function (): void {
        throw new RuntimeException('forced model delete failure');
    });

    expect(fn () => resolve(DeleteUploadedDocumentAction::class)->handle($fixture['uploadedDocument']))
        ->toThrow(RuntimeException::class, 'forced model delete failure')
        ->and(UploadedDocument::query()->whereKey($fixture['uploadedDocument']->id)
            ->exists())
        ->toBeTrue()
        ->and($fixture['documentRequest']->fresh()->status)
        ->toBe(DocumentRequestStatus::Submitted);

    // File must still exist — storage is only touched after a successful commit.
    Storage::disk('local')->assertExists($path);
});
