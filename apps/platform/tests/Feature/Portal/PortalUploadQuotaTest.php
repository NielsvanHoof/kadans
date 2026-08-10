<?php

declare(strict_types=1);

use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\DocumentUploadLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{plainTextToken: string, tenant: mixed, dossier: Dossier}
 */
function portalQuotaWorkspace(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'quota-client@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Quota dossier',
        'status' => DossierStatus::Draft,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    return [
        'tenant' => $tenant,
        'dossier' => $dossier->fresh(),
        'plainTextToken' => $result['plain_text_token'],
    ];
}

test('portal upload is rejected when the dossier byte quota would be exceeded', function (): void {
    Storage::fake('local');

    $context = portalQuotaWorkspace();
    actingInWorkspace($context['tenant']);

    $filledRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Already uploaded payslip',
        'status' => DocumentRequestStatus::Submitted,
    ]);

    UploadedDocument::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $filledRequest->id,
        'size_bytes' => DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER - 1_000,
    ]);

    $openRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Extra bank statement',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Fake size is kilobytes — 120 KB pushes the dossier over the remaining ~1 KB.
    $file = UploadedFile::fake()->create('statement.pdf', 120, 'application/pdf');

    $this->from(route('portal.show'))
        ->post(
            route('portal.document-requests.upload', [
                'documentRequest' => $openRequest->id,
            ]),
            ['documents' => [$file]],
        )
        ->assertRedirect(route('portal.show'))
        ->assertSessionHasErrors([
            'documents' => 'This dossier has reached its portal upload limit of '.DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER_LABEL.'. Remove a file or contact your advisor.',
        ]);

    actingInWorkspace($context['tenant']);

    expect(UploadedDocument::query()->where('document_request_id', $openRequest->id)->exists())->toBeFalse()
        ->and(UploadedDocument::query()->count())->toBe(1);
});

test('portal upload succeeds when the dossier is still under the byte quota', function (): void {
    Storage::fake('local');
    Queue::fake();

    $context = portalQuotaWorkspace();
    actingInWorkspace($context['tenant']);

    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Payslip January 2025',
        'status' => DocumentRequestStatus::Pending,
    ]);

    UploadedDocument::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => DocumentRequest::factory()->createOne([
            'tenant_id' => $context['tenant']->id,
            'dossier_id' => $context['dossier']->id,
            'title' => 'Prior upload',
            'status' => DocumentRequestStatus::Submitted,
        ])->id,
        'size_bytes' => 50_000,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(
        route('portal.document-requests.upload', [
            'documentRequest' => $documentRequest->id,
        ]),
        ['documents' => [UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf')]],
    )->assertRedirect(route('portal.show'));

    actingInWorkspace($context['tenant']);

    expect(UploadedDocument::query()->where('document_request_id', $documentRequest->id)->exists())->toBeTrue();
});
