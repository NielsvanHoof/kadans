<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Actions\Builder\DeleteDocumentRequestAction;
use App\Dossiers\Actions\DocumentRequests\UploadDocumentForRequestAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    config()->set('filesystems.default', 'local');
});

test('replacing an upload commits new metadata before deleting the old file', function (): void {
    $documentRequest = createDocumentRequestForUploadLifecycleTest();
    $upload = resolve(UploadDocumentForRequestAction::class);

    $original = $upload->handle(
        $documentRequest,
        UploadedFile::fake()->create('original.pdf', 100, 'application/pdf'),
    );
    $original->forceFill(['textract_job_id' => 'obsolete-textract-job'])->save();

    $replacement = $upload->handle(
        $documentRequest,
        UploadedFile::fake()->create('replacement.pdf', 120, 'application/pdf'),
    );

    expect($replacement->id)->toBe($original->id)
        ->and($replacement->original_filename)->toBe('replacement.pdf')
        ->and($replacement->textract_job_id)->toBeNull()
        ->and(UploadedDocument::query()->count())->toBe(1);

    Storage::disk('local')
        ->assertMissing($original->path)
        ->assertExists($replacement->path);

    Queue::assertPushed(
        ProcessUploadedDocumentJob::class,
        fn (ProcessUploadedDocumentJob $job): bool => $job->expectedPath === $replacement->path,
    );
});

test('a failed replacement keeps the previous database record and file', function (): void {
    $documentRequest = createDocumentRequestForUploadLifecycleTest();
    $upload = resolve(UploadDocumentForRequestAction::class);

    $original = $upload->handle(
        $documentRequest,
        UploadedFile::fake()->create('original.pdf', 100, 'application/pdf'),
    );

    $event = 'eloquent.updating: '.UploadedDocument::class;
    Event::listen($event, static function (): never {
        throw new RuntimeException('Simulated database failure.');
    });

    try {
        expect(fn () => $upload->handle(
            $documentRequest,
            UploadedFile::fake()->create('replacement.pdf', 120, 'application/pdf'),
        ))->toThrow(RuntimeException::class, 'Simulated database failure.');
    } finally {
        Event::forget($event);
    }

    $persistedUpload = UploadedDocument::query()->sole();

    expect($persistedUpload->path)->toBe($original->path)
        ->and($persistedUpload->original_filename)->toBe('original.pdf')
        ->and(Storage::disk('local')->allFiles())->toBe([$original->path]);
});

test('a failed initial upload removes the newly stored file', function (): void {
    $documentRequest = createDocumentRequestForUploadLifecycleTest();
    $upload = resolve(UploadDocumentForRequestAction::class);

    $event = 'eloquent.creating: '.UploadedDocument::class;
    Event::listen($event, static function (): never {
        throw new RuntimeException('Simulated database failure.');
    });

    try {
        expect(fn () => $upload->handle(
            $documentRequest,
            UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        ))->toThrow(RuntimeException::class, 'Simulated database failure.');
    } finally {
        Event::forget($event);
    }

    expect(UploadedDocument::query()->exists())->toBeFalse()
        ->and($documentRequest->fresh()->status)->toBe(DocumentRequestStatus::Pending)
        ->and(Storage::disk('local')->allFiles())
        ->toBeEmpty();
});

test('deleting a document request removes its database graph and stored file', function (): void {
    $documentRequest = createDocumentRequestForUploadLifecycleTest();
    $uploadedDocument = resolve(UploadDocumentForRequestAction::class)->handle(
        $documentRequest,
        UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
    );

    resolve(DeleteDocumentRequestAction::class)->handle($documentRequest);

    expect(DocumentRequest::query()->whereKey($documentRequest->id)->exists())->toBeFalse()
        ->and(UploadedDocument::query()->whereKey($uploadedDocument->id)->exists())->toBeFalse();

    Storage::disk('local')->assertMissing($uploadedDocument->path);
});

test('a failed document request deletion leaves its database graph and file intact', function (): void {
    $documentRequest = createDocumentRequestForUploadLifecycleTest();
    $uploadedDocument = resolve(UploadDocumentForRequestAction::class)->handle(
        $documentRequest,
        UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
    );

    $event = 'eloquent.deleting: '.DocumentRequest::class;
    Event::listen($event, static function (): never {
        throw new RuntimeException('Simulated database failure.');
    });

    try {
        expect(fn () => resolve(DeleteDocumentRequestAction::class)->handle($documentRequest))
            ->toThrow(RuntimeException::class, 'Simulated database failure.');
    } finally {
        Event::forget($event);
    }

    expect(DocumentRequest::query()->whereKey($documentRequest->id)->exists())->toBeTrue()
        ->and(UploadedDocument::query()->whereKey($uploadedDocument->id)->exists())->toBeTrue();

    Storage::disk('local')->assertExists($uploadedDocument->path);
});

function createDocumentRequestForUploadLifecycleTest(): DocumentRequest
{
    // provisionWorkspace sets the current tenant for scoped queries and audit logs.
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    return DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);
}
