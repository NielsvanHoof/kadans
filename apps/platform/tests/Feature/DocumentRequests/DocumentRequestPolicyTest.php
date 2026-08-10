<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Policies\DocumentRequestPolicy;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;

covers(DocumentRequestPolicy::class);

/**
 * @return array{policy: DocumentRequestPolicy, owner: mixed, tenant: Tenant, documentRequest: DocumentRequest, dossier: Dossier}
 */
function documentRequestPolicyContext(DossierStatus $status = DossierStatus::InReview): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => $status,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    return [
        'policy' => new DocumentRequestPolicy,
        'owner' => $owner,
        'tenant' => $tenant,
        'documentRequest' => $documentRequest,
        'dossier' => $dossier,
    ];
}

test('owner can view, upload, update, and review document requests on open dossiers', function (): void {
    $context = documentRequestPolicyContext();
    $policy = $context['policy'];
    $owner = $context['owner'];
    $documentRequest = $context['documentRequest'];

    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->view($owner, $documentRequest))->toBeTrue()
        ->and($policy->upload($owner, $documentRequest))->toBeTrue()
        ->and($policy->update($owner, $documentRequest))->toBeTrue()
        ->and($policy->review($owner, $documentRequest))->toBeTrue();
});

test('completed dossiers deny status-gated document request mutations', function (string $ability): void {
    $context = documentRequestPolicyContext(DossierStatus::Completed);
    $policy = $context['policy'];
    $owner = $context['owner'];
    $documentRequest = $context['documentRequest'];

    expect($policy->{$ability}($owner, $documentRequest))->toBeFalse();
})->with([
    'upload' => 'upload',
    'review' => 'review',
]);

test('reviewers can review but cannot upload or update structure', function (): void {
    $context = documentRequestPolicyContext();
    $policy = $context['policy'];
    $tenant = $context['tenant'];
    $documentRequest = $context['documentRequest'];

    $reviewer = workspaceMember($tenant, Role::Reviewer);

    expect($policy->view($reviewer, $documentRequest))->toBeTrue()
        ->and($policy->review($reviewer, $documentRequest))->toBeTrue()
        ->and($policy->upload($reviewer, $documentRequest))->toBeFalse()
        ->and($policy->create($reviewer))->toBeFalse();
});
