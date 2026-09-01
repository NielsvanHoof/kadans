<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Actions\Uploads\ClaimUploadTicketAction;
use App\Dossiers\Actions\Uploads\IssueUploadTicketAction;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\StagedUpload;
use App\Dossiers\Support\UploadTicket;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Presigned uploads move the write to the browser, so the request that follows
 * carries a claim about an object nobody in PHP has seen. Everything below is
 * an attempt to spend a ticket for something other than what it was issued for.
 */
covers(IssueUploadTicketAction::class, ClaimUploadTicketAction::class, UploadTicket::class);

beforeEach(function (): void {
    Storage::fake('s3');
    config(['filesystems.default' => 's3']);

    // Ticket issuing signs a PUT; the fake disk cannot, so stand in for the
    // signer. Everything this file actually asserts happens either side of it.
    Storage::disk('s3')->buildTemporaryUploadUrlsUsing(
        fn (string $path): array => ['url' => 'https://bucket.test/'.$path, 'headers' => []],
    );
});

function issueTicket(
    int $tenantId,
    string $filename = 'statement.pdf',
    UploadPurpose $purpose = UploadPurpose::Document,
    int $declaredBytes = 1024,
): string {
    return resolve(IssueUploadTicketAction::class)->handle(
        $purpose,
        $tenantId,
        $filename,
        'application/pdf',
        $declaredBytes,
        'file',
    )->ticket;
}

function claimTicket(
    string $ticket,
    UploadPurpose $purpose = UploadPurpose::Document,
    ?int $tenantId = null,
): StagedUpload {
    return resolve(ClaimUploadTicketAction::class)->handle($ticket, $purpose, $tenantId ?? 1, 'documents');
}

/** Write the object a sealed ticket points at, as the browser's PUT would. */
function completeUpload(string $ticket, int $sizeBytes = 1024): void
{
    $opened = UploadTicket::open($ticket);

    Storage::disk($opened->disk)->put($opened->path, str_repeat('x', $sizeBytes));
}

describe('issuing', function (): void {
    test('signs an upload into the tenant staging prefix', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        $data = resolve(IssueUploadTicketAction::class)->handle(
            UploadPurpose::Document,
            $tenant->id,
            'statement.pdf',
            'application/pdf',
            1024,
            'file',
        );

        $opened = UploadTicket::open($data->ticket);

        expect($opened)->toBeInstanceOf(UploadTicket::class)
            ->and($opened->tenantId)->toBe($tenant->id)
            ->and($opened->purpose)->toBe(UploadPurpose::Document)
            ->and($opened->path)->toStartWith('staging/tenants/'.$tenant->id.'/')
            ->and($opened->path)->toEndWith('.pdf')
            // The key must carry no client-supplied text; the filename is kept
            // in the sealed payload and written to the database instead.
            ->and($opened->path)->not->toContain('statement')
            ->and($opened->filename)->toBe('statement.pdf');
    });

    test('rejects an extension the purpose does not accept', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        expect(fn (): string => issueTicket($tenant->id, 'notes.exe'))
            ->toThrow(ValidationException::class, 'That file type is not supported.');
    });

    test('rejects a declared size above the ceiling before signing anything', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        expect(fn (): string => issueTicket($tenant->id, 'huge.pdf', declaredBytes: 21 * 1024 * 1024))
            ->toThrow(ValidationException::class, 'That file is too large. The maximum size is 20 MB.');
    });
});

describe('claiming', function (): void {
    test('returns the stored object metadata, not the declared metadata', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        // Declared as 1 KB at issue time; 4 KB actually lands.
        $ticket = issueTicket($tenant->id, 'statement.pdf', declaredBytes: 1024);
        completeUpload($ticket, 4096);

        $upload = claimTicket($ticket, tenantId: $tenant->id);

        expect($upload->sizeBytes)->toBe(4096)
            ->and($upload->originalFilename)->toBe('statement.pdf');
    });

    test('derives the mime type from the sealed extension, not from the stored object', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        // A presigned PUT signs only `host`, so the Content-Type on the stored
        // object is whatever the client chose to send. Nothing may depend on it.
        $ticket = issueTicket($tenant->id, 'statement.pdf');
        $opened = UploadTicket::open($ticket);
        Storage::disk($opened->disk)->put($opened->path, 'x', ['ContentType' => 'text/html']);

        expect(claimTicket($ticket, tenantId: $tenant->id)->mimeType)->toBe('application/pdf');
    });

    test('rejects an object that exceeds the ceiling despite a small declared size', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        $ticket = issueTicket($tenant->id, 'statement.pdf', declaredBytes: 1024);
        completeUpload($ticket, 21 * 1024 * 1024);

        expect(fn (): StagedUpload => claimTicket($ticket, tenantId: $tenant->id))
            ->toThrow(ValidationException::class, 'That file is too large. The maximum size is 20 MB.');
    });

    test('rejects a ticket whose object never arrived', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        expect(fn (): StagedUpload => claimTicket(issueTicket($tenant->id), tenantId: $tenant->id))
            ->toThrow(ValidationException::class, 'That file did not finish uploading. Try again.');
    });

    test('rejects an empty object rather than queueing work for nothing', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        $ticket = issueTicket($tenant->id);
        completeUpload($ticket, 0);

        expect(fn (): StagedUpload => claimTicket($ticket, tenantId: $tenant->id))
            ->toThrow(ValidationException::class, 'That file is empty.');
    });

    test('rejects a ticket issued for another tenant', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();
        ['tenant' => $otherTenant] = provisionWorkspace('Rival Accountants');

        $ticket = issueTicket($otherTenant->id);
        completeUpload($ticket);

        expect(fn (): StagedUpload => claimTicket($ticket, tenantId: $tenant->id))
            ->toThrow(ValidationException::class, 'That upload is no longer valid.');
    });

    test('rejects a ticket issued for another purpose', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        $ticket = issueTicket($tenant->id, 'call.mp3', UploadPurpose::AudioRecording);
        completeUpload($ticket);

        expect(fn (): StagedUpload => claimTicket($ticket, UploadPurpose::Document, $tenant->id))
            ->toThrow(ValidationException::class, 'That upload is no longer valid.');
    });

    test('rejects an expired ticket', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();

        $ticket = issueTicket($tenant->id);
        completeUpload($ticket);

        $this->travel(31)->minutes();

        expect(fn (): StagedUpload => claimTicket($ticket, tenantId: $tenant->id))
            ->toThrow(ValidationException::class, 'That upload is no longer valid.');
    });

    test('rejects a ticket that is not a sealed ticket at all', function (string $forged): void {
        ['tenant' => $tenant] = provisionWorkspace();

        expect(fn (): StagedUpload => claimTicket($forged, tenantId: $tenant->id))
            ->toThrow(ValidationException::class, 'That upload is no longer valid.');
    })->with([
        'plain object key' => 'staging/tenants/1/whatever.pdf',
        'empty' => '',
        'random base64' => 'eyJwdXJwb3NlIjoiZG9jdW1lbnQifQ==',
    ]);
});

test('read-only members cannot issue workspace upload tickets', function (string $purpose): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);

    $payload = match ($purpose) {
        'audio_recording' => [
            'purpose' => 'audio_recording',
            'filename' => 'call.mp3',
            'content_type' => 'audio/mpeg',
            'size_bytes' => 1024,
        ],
        default => [
            'purpose' => 'document',
            'filename' => 'statement.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 1024,
        ],
    };

    $this->actingAs($reader)
        ->postJson(workspaceRoute('workspaces.upload-tickets.store', $tenant), $payload)
        ->assertForbidden();
})->with([
    'document' => ['document'],
    'audio' => ['audio_recording'],
]);

test('the workspace endpoint issues a ticket sealed to the current workspace', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $response = $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.upload-tickets.store', $tenant), [
            'purpose' => 'document',
            'filename' => 'statement.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 1024,
        ])
        ->assertOk();

    $opened = UploadTicket::open($response->json('ticket'));

    expect($opened->tenantId)->toBe($tenant->id);
});

test('a ticket minted in one workspace cannot be spent in another', function (): void {
    Queue::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    ['tenant' => $otherTenant] = provisionWorkspace('Rival Accountants');

    // Route authorization passes — the actor really is an owner of this
    // workspace, uploading to its own document request. The only thing that
    // stops the write is the tenant sealed into the ticket.
    $ticket = issueTicket($otherTenant->id);
    completeUpload($ticket);

    // provisionWorkspace() leaves the rival workspace current; the factories
    // below write into this one.
    actingInWorkspace($tenant);
    $documentRequest = documentRequestForTicketTest($tenant);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.document-requests.upload', $tenant, [
            'dossier' => $documentRequest->dossier_id,
            'documentRequest' => $documentRequest,
        ]), ['documents' => [$ticket]])
        ->assertInvalid(['documents' => 'That upload is no longer valid. Choose the file again.']);

    expect(UploadedDocument::query()->exists())->toBeFalse();
});

function documentRequestForTicketTest(Tenant $tenant): DocumentRequest
{
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    return DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::File,
    ]);
}
