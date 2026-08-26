<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Console\Commands\Ocr\ConsumeTextractResultsCommand;
use App\DocumentProcessing\Contracts\Ocr\DocumentOverviewNormalizer;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ApplyTextractResultJob;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\CreatePipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Enums\PipelineType;
use App\Tenancy\Models\Tenant;
use Aws\Result;
use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Aws\Sqs\SqsClient;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Queue;

covers(ConsumeTextractResultsCommand::class);

/**
 * A signed-looking SNS envelope wrapping a Textract completion.
 */
function textractSnsBody(?string $jobId): string
{
    $inner = array_filter([
        'JobId' => $jobId,
        'Status' => 'SUCCEEDED',
        'API' => 'StartDocumentAnalysis',
    ], fn (?string $value): bool => $value !== null);

    return json_encode([
        'Type' => 'Notification',
        'MessageId' => 'sns-msg',
        'TopicArn' => 'arn:aws:sns:eu-west-1:123:textract',
        'Message' => json_encode($inner, JSON_THROW_ON_ERROR),
        'Timestamp' => '2026-01-01T00:00:00.000Z',
        'SignatureVersion' => '1',
        'Signature' => 'dGVzdA==',
        'SigningCertURL' => 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService.pem',
        'UnsubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=Unsubscribe',
    ], JSON_THROW_ON_ERROR);
}

test('textract consume command applies sns envelope and deletes the message', function (): void {
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

    $uploaded = UploadedDocument::factory()->processing()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'textract_job_id' => 'job-from-sqs',
    ]);
    $pipeline = resolve(CreatePipelineAction::class)->handle($uploaded, PipelineType::OcrDocument);

    config([
        'ocr.textract.results_queue_url' => 'https://sqs.eu-west-1.amazonaws.com/123/textract-results',
        'ocr.textract.sqs_wait_time_seconds' => 0,
        'ocr.textract.sqs_max_messages' => 1,
    ]);

    $snsBody = json_encode([
        'Type' => 'Notification',
        'MessageId' => 'sns-msg-1',
        'TopicArn' => 'arn:aws:sns:eu-west-1:123:textract',
        'Message' => json_encode([
            'JobId' => 'job-from-sqs',
            'Status' => 'SUCCEEDED',
            'API' => 'StartDocumentAnalysis',
        ], JSON_THROW_ON_ERROR),
        'Timestamp' => '2026-01-01T00:00:00.000Z',
        'SignatureVersion' => '1',
        'Signature' => 'dGVzdA==',
        'SigningCertURL' => 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService.pem',
        'UnsubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=Unsubscribe',
    ], JSON_THROW_ON_ERROR);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')
        ->once()
        ->andReturn(new Result([
            'Messages' => [
                [
                    'Body' => $snsBody,
                    'ReceiptHandle' => 'receipt-1',
                ],
            ],
        ]));
    $sqs->shouldReceive('deleteMessage')
        ->once()
        ->with([
            'QueueUrl' => 'https://sqs.eu-west-1.amazonaws.com/123/textract-results',
            'ReceiptHandle' => 'receipt-1',
        ])
        ->andReturn(new Result([]));

    $snsValidator = Mockery::mock(MessageValidator::class);
    $snsValidator->shouldReceive('validate')
        ->once()
        ->with(Mockery::type(Message::class));

    $textract = Mockery::mock(TextractClient::class);
    $textract->shouldReceive('getDocumentAnalysis')
        ->once()
        ->with(['JobId' => 'job-from-sqs'])
        ->andReturn(new Result([
            'JobStatus' => 'SUCCEEDED',
            'Blocks' => [
                [
                    'Id' => 'key-1',
                    'BlockType' => 'KEY_VALUE_SET',
                    'EntityTypes' => ['KEY'],
                    'Relationships' => [
                        ['Type' => 'CHILD', 'Ids' => ['word-key']],
                        ['Type' => 'VALUE', 'Ids' => ['value-1']],
                    ],
                ],
                [
                    'Id' => 'value-1',
                    'BlockType' => 'KEY_VALUE_SET',
                    'EntityTypes' => ['VALUE'],
                    'Confidence' => 90.0,
                    'Relationships' => [
                        ['Type' => 'CHILD', 'Ids' => ['word-value']],
                    ],
                ],
                ['Id' => 'word-key', 'BlockType' => 'WORD', 'Text' => 'From'],
                ['Id' => 'word-value', 'BlockType' => 'WORD', 'Text' => 'SQS'],
            ],
        ]));

    $overview = Mockery::mock(DocumentOverviewNormalizer::class);
    $overview->shouldReceive('describe')
        ->once()
        ->andReturn([
            'document_type' => null,
            'summary' => null,
            'notes' => [],
        ]);

    $this->app->instance(SqsClient::class, $sqs);
    $this->app->instance(MessageValidator::class, $snsValidator);
    $this->app->instance(TextractClient::class, $textract);
    $this->app->instance(DocumentOverviewNormalizer::class, $overview);

    // Production consume is a landlord daemon — no leftover HTTP tenant.
    Tenant::forgetCurrent();

    $this->artisan('textract:consume', ['--once' => true])
        ->assertSuccessful();

    // Restore for the assertion queries — the consume loop itself must not
    // leave a tenant current, but PipelineStep is tenant-scoped.
    $tenant->makeCurrent();

    $uploaded->refresh();

    /** @var array{fields: list<array{label: string, value: string, confidence: float|null, sensitivity: string|null}>} $payload */
    $payload = json_decode((string) $uploaded->extracted_text, true, 512, JSON_THROW_ON_ERROR);

    expect($uploaded->processing_status)->toBe(DocumentProcessingStatus::Completed)
        ->and($payload['fields'][0]['label'])->toBe('From')
        ->and($payload['fields'][0]['value'])->toBe('SQS')
        ->and($payload['fields'][0]['confidence'])->toBe(0.9)
        ->and($payload['fields'][0]['sensitivity'])->toBeNull();

    $pipeline->refresh();

    expect($pipeline->status)->toBe(PipelineStatus::Completed)
        ->and($pipeline->provider_job_id)->toBe('job-from-sqs')
        ->and($pipeline->steps()->pluck('status')->all())
        ->toBe([
            PipelineStepStatus::Completed,
            PipelineStepStatus::Completed,
            PipelineStepStatus::Completed,
        ]);
});

test('textract consume command queues the result instead of applying it inline', function (): void {
    Queue::fake();

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
    UploadedDocument::factory()->processing()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'textract_job_id' => 'job-queued',
    ]);

    config([
        'ocr.textract.results_queue_url' => 'https://sqs.eu-west-1.amazonaws.com/123/textract-results',
        'ocr.textract.sqs_wait_time_seconds' => 0,
        'ocr.textract.sqs_max_messages' => 1,
    ]);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')
        ->once()
        // The window only has to cover parse-and-dispatch now, not applying a
        // whole batch, which is what let later messages reappear mid-work.
        ->with(Mockery::on(fn (array $arguments): bool => ($arguments['VisibilityTimeout'] ?? null) === 30))
        ->andReturn(new Result([
            'Messages' => [[
                'Body' => textractSnsBody('job-queued'),
                'ReceiptHandle' => 'receipt-queued',
            ]],
        ]));
    $sqs->shouldReceive('deleteMessage')->once()->andReturn(new Result([]));

    $snsValidator = Mockery::mock(MessageValidator::class);
    $snsValidator->shouldReceive('validate')->once()->with(Mockery::type(Message::class));

    // The whole point: no Textract call and no Bedrock call happen on the poll
    // loop, so one large document cannot stall every other tenant's OCR.
    $textract = Mockery::mock(TextractClient::class);
    $textract->shouldNotReceive('getDocumentAnalysis');

    $this->app->instance(SqsClient::class, $sqs);
    $this->app->instance(MessageValidator::class, $snsValidator);
    $this->app->instance(TextractClient::class, $textract);

    Tenant::forgetCurrent();

    $this->artisan('textract:consume', ['--once' => true])->assertSuccessful();

    Queue::assertPushed(
        ApplyTextractResultJob::class,
        fn (ApplyTextractResultJob $job): bool => $job->textractJobId === 'job-queued'
            && $job->queue === 'ocr'
            && ($job->notification['Status'] ?? null) === 'SUCCEEDED',
    );
});

test('textract consume command leaves an unparseable body for the queue redrive policy', function (): void {
    Queue::fake();

    provisionWorkspace();

    config([
        'ocr.textract.results_queue_url' => 'https://sqs.eu-west-1.amazonaws.com/123/textract-results',
        'ocr.textract.sqs_wait_time_seconds' => 0,
        'ocr.textract.sqs_max_messages' => 1,
    ]);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')->once()->andReturn(new Result([
        'Messages' => [[
            'Body' => 'not json at all',
            'ReceiptHandle' => 'receipt-garbage',
        ]],
    ]));
    // Deliberately not deleted. A body this process cannot parse is a transport
    // problem, and the queue's own redrive policy is what should surface it on
    // the DLQ rather than this command silently dropping it.
    $sqs->shouldNotReceive('deleteMessage');

    $this->app->instance(SqsClient::class, $sqs);
    $this->app->instance(MessageValidator::class, Mockery::mock(MessageValidator::class));

    Tenant::forgetCurrent();

    $this->artisan('textract:consume', ['--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});

test('textract consume command drops a notification carrying no job id', function (): void {
    Queue::fake();

    provisionWorkspace();

    config([
        'ocr.textract.results_queue_url' => 'https://sqs.eu-west-1.amazonaws.com/123/textract-results',
        'ocr.textract.sqs_wait_time_seconds' => 0,
        'ocr.textract.sqs_max_messages' => 1,
    ]);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')->once()->andReturn(new Result([
        'Messages' => [[
            'Body' => textractSnsBody(null),
            'ReceiptHandle' => 'receipt-no-job',
        ]],
    ]));
    // Unlike an unparseable body, redelivery will never add a job id to this.
    $sqs->shouldReceive('deleteMessage')->once()->andReturn(new Result([]));

    $snsValidator = Mockery::mock(MessageValidator::class);
    $snsValidator->shouldReceive('validate')->once()->with(Mockery::type(Message::class));

    $this->app->instance(SqsClient::class, $sqs);
    $this->app->instance(MessageValidator::class, $snsValidator);

    Tenant::forgetCurrent();

    $this->artisan('textract:consume', ['--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});

test('textract consume command drops a notification that matches no document', function (): void {
    Queue::fake();

    provisionWorkspace();

    config([
        'ocr.textract.results_queue_url' => 'https://sqs.eu-west-1.amazonaws.com/123/textract-results',
        'ocr.textract.sqs_wait_time_seconds' => 0,
        'ocr.textract.sqs_max_messages' => 1,
    ]);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')->once()->andReturn(new Result([
        'Messages' => [[
            'Body' => textractSnsBody('job-from-another-era'),
            'ReceiptHandle' => 'receipt-orphan',
        ]],
    ]));
    $sqs->shouldReceive('deleteMessage')->once()->andReturn(new Result([]));

    $snsValidator = Mockery::mock(MessageValidator::class);
    $snsValidator->shouldReceive('validate')->once()->with(Mockery::type(Message::class));

    $this->app->instance(SqsClient::class, $sqs);
    $this->app->instance(MessageValidator::class, $snsValidator);

    Tenant::forgetCurrent();

    $this->artisan('textract:consume', ['--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});

test('textract consume command rejects an SNS envelope with an invalid signature', function (): void {
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
    $uploaded = UploadedDocument::factory()->processing()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'textract_job_id' => 'job-forged',
    ]);

    config([
        'ocr.textract.results_queue_url' => 'https://sqs.eu-west-1.amazonaws.com/123/textract-results',
        'ocr.textract.sqs_wait_time_seconds' => 0,
        'ocr.textract.sqs_max_messages' => 1,
    ]);

    $snsBody = json_encode([
        'Type' => 'Notification',
        'MessageId' => 'sns-msg-forged',
        'TopicArn' => 'arn:aws:sns:eu-west-1:123:textract',
        'Message' => json_encode([
            'JobId' => 'job-forged',
            'Status' => 'SUCCEEDED',
            'API' => 'StartDocumentAnalysis',
        ], JSON_THROW_ON_ERROR),
        'Timestamp' => '2026-01-01T00:00:00.000Z',
        'SignatureVersion' => '1',
        'Signature' => 'Zm9yZ2Vk',
        'SigningCertURL' => 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService.pem',
        'UnsubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=Unsubscribe',
    ], JSON_THROW_ON_ERROR);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')
        ->once()
        ->andReturn(new Result([
            'Messages' => [[
                'Body' => $snsBody,
                'ReceiptHandle' => 'receipt-forged',
                'MessageId' => 'sqs-forged',
            ]],
        ]));
    $sqs->shouldReceive('deleteMessage')
        ->once()
        ->with([
            'QueueUrl' => 'https://sqs.eu-west-1.amazonaws.com/123/textract-results',
            'ReceiptHandle' => 'receipt-forged',
        ])
        ->andReturn(new Result([]));

    $snsValidator = Mockery::mock(MessageValidator::class);
    $snsValidator->shouldReceive('validate')
        ->once()
        ->andThrow(new InvalidSnsMessageException('The message signature is invalid.'));

    $textract = Mockery::mock(TextractClient::class);
    $textract->shouldNotReceive('getDocumentAnalysis');

    $overview = Mockery::mock(DocumentOverviewNormalizer::class);
    $overview->shouldNotReceive('describe');

    $this->app->instance(SqsClient::class, $sqs);
    $this->app->instance(MessageValidator::class, $snsValidator);
    $this->app->instance(TextractClient::class, $textract);
    $this->app->instance(DocumentOverviewNormalizer::class, $overview);

    Tenant::forgetCurrent();

    $this->artisan('textract:consume', ['--once' => true])
        ->assertSuccessful();

    $uploaded->refresh();

    expect($uploaded->processing_status)->toBe(DocumentProcessingStatus::Processing)
        ->and($uploaded->extracted_text)->toBeNull();
});
