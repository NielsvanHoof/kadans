<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Policies\UploadedDocumentPolicy;
use App\Tenancy\Enums\Role;

test('owner can view and download uploaded documents', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $uploadedDocument = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $policy = new UploadedDocumentPolicy;

    expect($policy->view($owner, $uploadedDocument))->toBeTrue()
        ->and($policy->download($owner, $uploadedDocument))->toBeTrue();
});

test('reviewers can view uploads but cannot download without the download permission', function (): void {
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
    ]);
    $uploadedDocument = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $policy = new UploadedDocumentPolicy;

    expect($policy->view($reviewer, $uploadedDocument))->toBeTrue()
        ->and($policy->download($reviewer, $uploadedDocument))->toBeFalse();
});
