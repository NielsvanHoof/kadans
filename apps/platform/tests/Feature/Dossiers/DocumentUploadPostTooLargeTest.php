<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DocumentUploadLimits;

test('an oversized document upload reports the configured post size limit', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'configuration' => ['is_required' => true, 'allow_multiple' => true],
    ]);

    $uploadUrl = workspaceRoute('workspaces.dossiers.document-requests.upload', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $documentRequest,
    ]);

    $expected = 'The upload is too large. The maximum total size is '.DocumentUploadLimits::MAX_POST_SIZE_LABEL.'.';

    // ValidatePostSize compares CONTENT_LENGTH to post_max_size before the body is read.
    // Visit the form first so the session cookie exists (mirrors a real oversized POST).
    $this->actingAs($owner)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk();

    $this->from($uploadUrl)
        ->call(
            'POST',
            $uploadUrl,
            [],
            [],
            [],
            [
                'CONTENT_LENGTH' => (string) (300 * 1024 * 1024),
                'HTTP_ACCEPT' => 'text/html',
            ],
        )
        ->assertRedirect($uploadUrl)
        ->assertSessionHasErrors(['documents' => $expected]);
});
