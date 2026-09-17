<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/*
 * Serving the first-page preview the document-scan Lambda rendered.
 *
 * Two deliberate departures from the download and preview endpoints are pinned
 * here, because both look like bugs until you know why: the thumbnail does not
 * go through the malware gate, and it is not behind password confirmation.
 */

/** @return array{owner: User, tenant: Tenant, document: UploadedDocument} */
function thumbnailFixture(?string $thumbnailPath = 'thumbnails/tenants/1/dossiers/1/01JBQ.jpg'): array
{
    Storage::fake('local');

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    if ($thumbnailPath !== null) {
        Storage::disk('local')->put($thumbnailPath, 'jpeg-bytes');
    }

    $document = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/01JBQ.pdf',
        'original_filename' => 'payslip.pdf',
        'thumbnail_path' => $thumbnailPath,
    ]);

    return ['owner' => $owner, 'tenant' => $tenant, 'document' => $document];
}

function getThumbnail(User $owner, Tenant $tenant, UploadedDocument $document, bool $confirmPassword = false)
{
    $request = test()->actingAs($owner);

    if ($confirmPassword) {
        $request = $request->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }

    return $request->get(workspaceRoute(
        'workspaces.uploaded-documents.thumbnail',
        $tenant,
        ['uploadedDocument' => $document],
    ));
}

test('a rendered thumbnail is served inline', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'document' => $document] = thumbnailFixture();

    $response = getThumbnail($owner, $tenant, $document, confirmPassword: true);

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('inline')
        // Never the client's filename: this is our JPEG, and the upload's name
        // has no business in a content-disposition header.
        ->and($response->headers->get('content-disposition'))->not->toContain('payslip')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

/*
 * The departure that looks most like a bug. A thumbnail carries no
 * GuardDutyMalwareScanStatus tag of its own — it is output this application
 * rendered, not bytes a client uploaded — so putting it through the gate would
 * fail closed on every thumbnail forever. Safety comes from the ordering
 * instead: the render is triggered by the clean verdict, so an object that was
 * never cleared is never rendered.
 */
test('the thumbnail is served without consulting the malware gate', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'document' => $document] = thumbnailFixture();

    // Scanning enforced, and nothing has tagged this object clean. The download
    // endpoint refuses under exactly these conditions; the thumbnail does not.
    config()->set('security.malware_scanning.enabled', true);

    getThumbnail($owner, $tenant, $document, confirmPassword: true)->assertOk();
});

/*
 * Password confirmation cannot apply here: an <img> in a document list would
 * follow the redirect to the password page and render as a broken image, which
 * is the entire feature. The accepted cost is that a member whose confirmation
 * has lapsed still sees the rendered first page.
 */
test('the thumbnail does not require password confirmation, unlike download', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'document' => $document] = thumbnailFixture();

    getThumbnail($owner, $tenant, $document)->assertOk();

    // The contrast that makes the decision above legible.
    test()->actingAs($owner)
        ->get(workspaceRoute(
            'workspaces.uploaded-documents.download',
            $tenant,
            ['uploadedDocument' => $document],
        ))
        ->assertRedirect();
});

/*
 * No thumbnail is ordinary, not exceptional: the scan may be in flight, the
 * verdict may not have been clean, or the format may have no renderer. The
 * interface falls back to a file-type icon on a 404.
 */
test('a document with no thumbnail is a 404, not an error', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'document' => $document] = thumbnailFixture(thumbnailPath: null);

    getThumbnail($owner, $tenant, $document, confirmPassword: true)->assertNotFound();
});

/*
 * Skipping password confirmation must not have skipped authorisation with it.
 *
 * The refusal is a 404 rather than a 403, and that is the tenant scope doing
 * the work before the policy is ever consulted: an outsider cannot resolve the
 * route binding at all, so the response does not even confirm the document
 * exists.
 */
test('a member of another firm cannot read the thumbnail', function (): void {
    ['tenant' => $tenant, 'document' => $document] = thumbnailFixture();

    ['owner' => $outsider] = provisionWorkspace('Other Firm');

    getThumbnail($outsider, $tenant, $document, confirmPassword: true)
        ->assertNotFound();
});
