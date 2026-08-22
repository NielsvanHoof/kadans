<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

test('an oversized POST reports a request-size error rather than a CSRF failure', function (): void {
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

    $expected = 'That request was too large to process. Try again with less data.';

    // Uploads no longer POST their bytes, so this path is now a safety net
    // rather than an upload concern — but it is still the difference between
    // a legible error and a bare CSRF failure, because ValidatePostSize
    // compares CONTENT_LENGTH to post_max_size before the body is read and
    // PHP has already discarded the token by then.
    // Visit the form first so the session cookie exists.
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
