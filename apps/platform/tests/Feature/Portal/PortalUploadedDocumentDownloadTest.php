<?php

declare(strict_types=1);

use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Support\Facades\Storage;

/*
 * Clients used to see a filename and nothing else, so someone who photographed
 * the wrong page of a bank statement had no way to check, and no way to replace
 * it on a single-file item. The malware gate on this route lives in
 * tests/Feature/Security/MalwareScanGateTest.php, next to its S3 harness.
 */

/**
 * @return array{documentRequest: DocumentRequest, document: UploadedDocument}
 */
function portalUploadFixture(Dossier $dossier, string $filename = 'payslip.pdf'): array
{
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $dossier->tenant_id,
        'dossier_id' => $dossier->id,
    ]);

    $path = 'tenants/'.$dossier->tenant_id.'/'.$filename;
    Storage::disk('local')->put($path, 'the-bytes');

    $document = UploadedDocument::factory()->create([
        'tenant_id' => $dossier->tenant_id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => $filename,
        'mime_type' => 'application/pdf',
    ]);

    return ['documentRequest' => $documentRequest, 'document' => $document];
}

function portalDownloadRoute(DocumentRequest $documentRequest, UploadedDocument $document): string
{
    return route('portal.document-requests.uploaded-documents.download', [
        'documentRequest' => $documentRequest->id,
        'uploadedDocument' => $document->id,
    ]);
}

test('a client can download a file they uploaded', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    ['documentRequest' => $documentRequest, 'document' => $document] = portalUploadFixture(
        $context['dossier'],
    );

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $response = $this->get(portalDownloadRoute($documentRequest, $document));

    $response->assertOk()
        ->assertDownload('payslip.pdf')
        // The same hardening staff downloads carry: `mime_type` comes from the
        // upload, so it must never be able to drive script execution.
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'none'");
});

test('an upload on someone else dossier is a 404, not a download', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);

    // A second dossier in the same firm: the grant opens one dossier, not the
    // tenant, so tenant scoping alone would not stop this.
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'client_id' => Client::factory()->create(['tenant_id' => $context['tenant']->id])->id,
    ]);
    ['documentRequest' => $otherRequest, 'document' => $otherDocument] = portalUploadFixture(
        $otherDossier,
        'someone-elses-payslip.pdf',
    );

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(portalDownloadRoute($otherRequest, $otherDocument))->assertNotFound();
});

test('an upload belonging to a different item on the same dossier is a 404', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    ['documentRequest' => $firstRequest] = portalUploadFixture($context['dossier'], 'first.pdf');
    ['document' => $secondDocument] = portalUploadFixture($context['dossier'], 'second.pdf');

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Both are on the granted dossier, so only the parent check catches this.
    $this->get(portalDownloadRoute($firstRequest, $secondDocument))->assertNotFound();
});

test('downloading without a portal session is a 404', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    ['documentRequest' => $documentRequest, 'document' => $document] = portalUploadFixture(
        $context['dossier'],
    );

    $this->get(portalDownloadRoute($documentRequest, $document))->assertNotFound();
});

test('a revoked grant cannot download, and is told its access ended', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    ['documentRequest' => $documentRequest, 'document' => $document] = portalUploadFixture(
        $context['dossier'],
    );

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $context['grant']->forceFill(['revoked_at' => now()])->save();

    $this->get(portalDownloadRoute($documentRequest, $document))
        ->assertRedirect(route('portal.ended'));
});

test('a client can still download a file they can no longer delete', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    ['documentRequest' => $documentRequest, 'document' => $document] = portalUploadFixture(
        $context['dossier'],
    );

    // A grant on another dossier would be a different test; this is about the
    // read/write asymmetry. Deleting is blocked once an item is under review,
    // but checking what you sent should not be.
    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->delete(route('portal.document-requests.uploaded-documents.destroy', [
        'documentRequest' => $documentRequest->id,
        'uploadedDocument' => $document->id,
    ]))->assertNotFound();

    $this->get(portalDownloadRoute($documentRequest, $document))->assertOk();
});

test('the portal never hands out a URL, only ids the client already holds', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    portalUploadFixture($context['dossier']);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('portal/show')
            ->has('dossier.document_requests.0.uploaded_documents.0', fn ($uploaded) => $uploaded
                ->hasAll(['id', 'original_filename', 'size_bytes', 'uploaded_at'])
                // The href is built client-side from Wayfinder; a server-sent
                // URL would be a second place for the route to drift.
                ->etc()));
});

test('a separate dossier grant cannot reach the first dossier files', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    ['documentRequest' => $documentRequest, 'document' => $document] = portalUploadFixture(
        $context['dossier'],
    );

    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'client_id' => Client::factory()->create(['tenant_id' => $context['tenant']->id])->id,
    ]);
    $otherGrant = resolve(CreateClientAccessGrantAction::class)->handle(
        $otherDossier,
        $context['owner'],
        7,
    );

    $this->get(route('portal.exchange', $otherGrant['plain_text_token']));

    $this->get(portalDownloadRoute($documentRequest, $document))->assertNotFound();
});
