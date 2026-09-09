<?php

declare(strict_types=1);

use App\Audit\Actions\LogAuditActivityAction;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\Ocr\ProcessUploadedDocumentJob;
use App\DocumentProcessing\Services\Ocr\Drivers\TextractDocumentOcr;
use App\DocumentProcessing\Services\Ocr\OcrOrchestrator;
use App\DocumentProcessing\Transitions\Ocr\UploadedDocumentProcessingTransitions;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Support\PipelineState;
use Aws\Result;
use Aws\Textract\TextractClient;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config([
        'ocr.driver' => 'textract',
        'ocr.textract.bucket' => 'test-documents',
        'ocr.textract.sns_topic_arn' => 'arn:aws:sns:eu-west-1:123:textract',
        'ocr.textract.sns_role_arn' => 'arn:aws:iam::123:role/textract-sns',
        'ocr.textract.region' => 'eu-west-1',
    ]);
});

test('textract start persists job id and leaves document processing', function (): void {
    Event::fake([MessageLogged::class]);
    $this->freezeTime();

    $uploaded = createUploadedDocumentForTextractTest();

    $textract = Mockery::mock(TextractClient::class);
    $textract->shouldReceive('startDocumentAnalysis')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => ($args['DocumentLocation']['S3Object']['Bucket'] ?? null) === 'test-documents'
            && ($args['DocumentLocation']['S3Object']['Name'] ?? null) === $uploaded->path
            && ($args['FeatureTypes'] ?? null) === ['FORMS', 'TABLES']
            && ($args['NotificationChannel']['SNSTopicArn'] ?? null) === 'arn:aws:sns:eu-west-1:123:textract'
            && ($args['NotificationChannel']['RoleArn'] ?? null) === 'arn:aws:iam::123:role/textract-sns'
            && ($args['ClientRequestToken'] ?? null) === hash('sha256', $uploaded->id.':'.$uploaded->path.':'.now()->toIso8601String())
            && ($args['JobTag'] ?? null) === (string) $uploaded->id))
        ->andReturn(new Result(['JobId' => 'textract-job-abc']));

    $this->app->instance(TextractClient::class, $textract);

    new ProcessUploadedDocumentJob($uploaded->id, $uploaded->path)->handle(
        resolve(OcrOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineState::class),
        resolve(UploadedDocumentProcessingTransitions::class),
    );

    $uploaded->refresh();

    expect($uploaded->processing_status)->toBe(DocumentProcessingStatus::Processing)
        ->and($uploaded->textract_job_id)->toBe('textract-job-abc')
        ->and($uploaded->extracted_text)->toBeNull()
        ->and($uploaded->processing_finished_at)->toBeNull();

    Event::assertDispatched(fn (MessageLogged $log): bool => $log->level === 'info'
        && str_contains($log->message, 'OCR: Textract job started — awaiting SNS/SQS completion.')
        && ($log->context['textract_job_id'] ?? null) === 'textract-job-abc');

    Event::assertDispatched(fn (MessageLogged $log): bool => $log->level === 'info'
        && str_contains($log->message, 'OCR: orchestrator deferred')
        && ($log->context['textract_job_id'] ?? null) === 'textract-job-abc');
});

test('textract start sends a different client request token after a later claim', function (): void {
    $this->freezeTime();

    $uploaded = createUploadedDocumentForTextractTest([
        'processing_status' => DocumentProcessingStatus::Processing,
        'processing_started_at' => now(),
    ]);

    $tokens = [];

    $textract = Mockery::mock(TextractClient::class);
    $textract->shouldReceive('startDocumentAnalysis')
        ->twice()
        ->with(Mockery::on(function (array $args) use (&$tokens): bool {
            $token = $args['ClientRequestToken'] ?? null;

            if (! is_string($token) || $token === '') {
                return false;
            }

            $tokens[] = $token;

            return true;
        }))
        ->andReturn(new Result(['JobId' => 'textract-job-abc']));

    $this->app->instance(TextractClient::class, $textract);

    resolve(TextractDocumentOcr::class)->start($uploaded);

    $uploaded->forceFill([
        'processing_started_at' => now()->addMinute(),
    ])->save();

    resolve(TextractDocumentOcr::class)->start($uploaded->fresh());

    expect($tokens)->toHaveCount(2)
        ->and($tokens[0])->not->toBe($tokens[1]);
});

test('textract start does not attach a job id after the upload is replaced', function (): void {
    $uploaded = createUploadedDocumentForTextractTest([
        'processing_status' => DocumentProcessingStatus::Processing,
        'processing_started_at' => now(),
    ]);

    $textract = Mockery::mock(TextractClient::class);
    $textract->shouldReceive('startDocumentAnalysis')
        ->once()
        ->andReturnUsing(function () use ($uploaded): Result {
            UploadedDocument::query()
                ->whereKey($uploaded->id)
                ->update([
                    'path' => 'replacement.pdf',
                    'processing_status' => DocumentProcessingStatus::Pending->value,
                ]);

            return new Result(['JobId' => 'obsolete-job']);
        });

    $this->app->instance(TextractClient::class, $textract);

    resolve(TextractDocumentOcr::class)->start($uploaded);

    $uploaded->refresh();

    expect($uploaded->path)->toBe('replacement.pdf')
        ->and($uploaded->processing_status)->toBe(DocumentProcessingStatus::Pending)
        ->and($uploaded->textract_job_id)->toBeNull();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createUploadedDocumentForTextractTest(array $overrides = []): UploadedDocument
{
    // provisionWorkspace sets the current tenant for scoped queries and audit logs.
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    return UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 's3',
        'path' => 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/payslip.pdf',
        'original_filename' => 'payslip.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 4096,
        'processing_status' => DocumentProcessingStatus::Pending,
        ...$overrides,
    ]);
}
