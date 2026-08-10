<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Policies\UploadedDocumentPolicy;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;

covers(UploadedDocumentPolicy::class);

/**
 * @return array{owner: User, tenant: Tenant, uploadedDocument: UploadedDocument, policy: UploadedDocumentPolicy}
 */
function uploadedDocumentPolicyContext(): array
{
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

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'uploadedDocument' => $uploadedDocument,
        'policy' => new UploadedDocumentPolicy,
    ];
}

test('owner can view and download uploaded documents', function (): void {
    [
        'owner' => $owner,
        'uploadedDocument' => $uploadedDocument,
        'policy' => $policy,
    ] = uploadedDocumentPolicyContext();

    expect($policy->view($owner, $uploadedDocument))->toBeTrue()
        ->and($policy->download($owner, $uploadedDocument))->toBeTrue();
});

test('reviewers can view uploads but cannot download without the download permission', function (): void {
    [
        'tenant' => $tenant,
        'uploadedDocument' => $uploadedDocument,
        'policy' => $policy,
    ] = uploadedDocumentPolicyContext();
    $reviewer = workspaceMember($tenant, Role::Reviewer);

    expect($policy->view($reviewer, $uploadedDocument))->toBeTrue()
        ->and($policy->download($reviewer, $uploadedDocument))->toBeFalse();
});
