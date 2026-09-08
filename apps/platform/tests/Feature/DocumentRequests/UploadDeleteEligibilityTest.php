<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Transitions\DocumentRequests\DocumentRequestTransitions;

covers(DocumentRequestTransitions::class);

/**
 * Upload-delete eligibility used to live in two adapters that disagreed: the
 * portal controller inlined `allow_multiple && Submitted`, while the staff
 * route and policy had no such guard at all. .ai/rules/client-portal.md forbids
 * duplicating a domain rule in an HTTP adapter, so it now lives in one place.
 *
 * The asymmetry that remains is a recorded product decision, not an oversight —
 * see DocumentRequestTransitions::canDeleteUpload(). These tests exist so it
 * cannot drift back without someone deciding to.
 */
function eligibilityRequest(
    DocumentRequestStatus $status,
    bool $allowMultiple,
    DossierStatus $dossierStatus = DossierStatus::InReview,
): DocumentRequest {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => $dossierStatus,
    ]);

    return DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => $status,
        'configuration' => ['is_required' => true, 'allow_multiple' => $allowMultiple],
    ]);
}

test('a client may only remove a file from a multi-file request awaiting review', function (
    DocumentRequestStatus $status,
    bool $allowMultiple,
    bool $expected,
): void {
    $documentRequest = eligibilityRequest($status, $allowMultiple);

    expect(resolve(DocumentRequestTransitions::class)
        ->canDeleteUpload($documentRequest, SubmissionContext::Portal))
        ->toBe($expected);
})->with([
    'multi-file, submitted' => [DocumentRequestStatus::Submitted, true, true],
    'multi-file, pending' => [DocumentRequestStatus::Pending, true, false],
    // An accepted or rejected item is a reviewed record; pulling its file out
    // would leave the review describing something that is no longer there.
    'multi-file, accepted' => [DocumentRequestStatus::Accepted, true, false],
    'multi-file, rejected' => [DocumentRequestStatus::Rejected, true, false],
    'single-file, submitted' => [DocumentRequestStatus::Submitted, false, false],
]);

test('staff may remove a file whatever the request status, including its only one', function (
    DocumentRequestStatus $status,
    bool $allowMultiple,
): void {
    $documentRequest = eligibilityRequest($status, $allowMultiple);

    // The decision: staff are named in the audit trail, and fixing a client's
    // mistake is ordinary work that should not require rejecting and
    // re-requesting a document the client already sent.
    expect(resolve(DocumentRequestTransitions::class)
        ->canDeleteUpload($documentRequest, SubmissionContext::Staff))
        ->toBeTrue();
})->with([
    'multi-file, submitted' => [DocumentRequestStatus::Submitted, true],
    'single-file, submitted' => [DocumentRequestStatus::Submitted, false],
    'single-file, accepted' => [DocumentRequestStatus::Accepted, false],
    'single-file, rejected' => [DocumentRequestStatus::Rejected, false],
]);

test('a completed dossier is closed to deletion for both staff and clients', function (
    SubmissionContext $context,
): void {
    $documentRequest = eligibilityRequest(
        DocumentRequestStatus::Submitted,
        allowMultiple: true,
        dossierStatus: DossierStatus::Completed,
    );

    expect(resolve(DocumentRequestTransitions::class)
        ->canDeleteUpload($documentRequest, $context))
        ->toBeFalse();
})->with([
    'staff' => SubmissionContext::Staff,
    'portal' => SubmissionContext::Portal,
]);
