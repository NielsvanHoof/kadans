<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Console\Commands\Scanning\ConsumeScanResultsCommand;
use App\Dossiers\Enums\MalwareScanStatus;
use App\Dossiers\Jobs\Scanning\ApplyScanResultJob;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Queue;

covers(ConsumeScanResultsCommand::class);

/**
 * The third landlord daemon, and the one with the trap that is invisible until
 * production: ApplyScanResultJob is tenant-aware, so dispatching it without a
 * current tenant puts no tenantId on the payload and Spatie deletes the job on
 * the worker. The verdict would simply never be applied, and nothing would say
 * so. The tenant assertions below are the point of this file.
 */
function scanConsumeDocument(): UploadedDocument
{
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $document = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 's3',
        'path' => sprintf('tenants/%d/dossiers/%d/01JBQ.pdf', $tenant->id, $dossier->id),
    ]);

    // Production consume is a landlord daemon — no leftover HTTP tenant.
    Tenant::forgetCurrent();

    return $document;
}

/**
 * @param  list<array<string, string>>  $messages
 */
function fakeScanQueue(array $messages, bool $expectDelete): void
{
    config([
        'scanning.results_queue_url' => 'https://sqs.eu-west-1.amazonaws.com/123/scan-results',
        'scanning.sqs_wait_time_seconds' => 0,
        'scanning.sqs_max_messages' => 1,
    ]);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')->once()->andReturn(new Result(['Messages' => $messages]));

    if ($expectDelete) {
        $sqs->shouldReceive('deleteMessage')->once()->andReturn(new Result([]));
    } else {
        $sqs->shouldReceive('deleteMessage')->never();
    }

    app()->instance(SqsClient::class, $sqs);
}

/** @param array<string, mixed> $verdict */
function scanQueueMessage(array $verdict, string $receipt = 'receipt-1'): array
{
    return [
        'Body' => json_encode($verdict, JSON_THROW_ON_ERROR),
        'ReceiptHandle' => $receipt,
        'MessageId' => 'msg-1',
    ];
}

test('a verdict is queued for the tenant that owns the object', function (): void {
    Queue::fake();

    $document = scanConsumeDocument();

    fakeScanQueue([scanQueueMessage([
        'key' => $document->path,
        'status' => 'NO_THREATS_FOUND',
        'thumbnailKey' => 'thumbnails/'.$document->path.'.jpg',
    ])], expectDelete: true);

    $this->artisan('scan:consume', ['--once' => true])->assertSuccessful();

    Queue::assertPushed(
        ApplyScanResultJob::class,
        fn (ApplyScanResultJob $job): bool => $job->objectKey === $document->path,
    );
});

/*
 * The whole reason the command looks the tenant up rather than reading it out of
 * the key.
 *
 * Deliberately without Queue::fake, so the job actually runs. That is what makes
 * this an assertion about tenancy rather than about dispatch: the action's
 * lookup is tenant-scoped, so if the tenant were not current the verdict would
 * find no document and the row below would be untouched — which is exactly the
 * production failure, where Spatie discards a tenant-aware job dispatched
 * without one and nothing reports it.
 */
test('the verdict reaches the document, which it can only do inside the owning tenant', function (): void {
    $document = scanConsumeDocument();

    fakeScanQueue([scanQueueMessage([
        'key' => $document->path,
        'status' => 'NO_THREATS_FOUND',
        'thumbnailKey' => 'thumbnails/'.$document->path.'.jpg',
    ])], expectDelete: true);

    $this->artisan('scan:consume', ['--once' => true])->assertSuccessful();

    $document->refresh();

    expect($document->malware_scan_status)->toBe(MalwareScanStatus::NoThreatsFound)
        ->and($document->thumbnail_path)->toBe('thumbnails/'.$document->path.'.jpg');
});

/*
 * A message that can never be acted on is deleted rather than redelivered five
 * times first — no key means nothing downstream can match a file, and
 * redelivery will not add one.
 */
test('a verdict with no object key is deleted without being queued', function (): void {
    Queue::fake();

    scanConsumeDocument();

    fakeScanQueue([scanQueueMessage(['status' => 'NO_THREATS_FOUND'])], expectDelete: true);

    $this->artisan('scan:consume', ['--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});

test('a verdict for a key nothing owns is deleted without being queued', function (): void {
    Queue::fake();

    scanConsumeDocument();

    fakeScanQueue([scanQueueMessage([
        'key' => 'tenants/999/dossiers/999/gone.pdf',
        'status' => 'NO_THREATS_FOUND',
    ])], expectDelete: true);

    $this->artisan('scan:consume', ['--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});

/*
 * Left visible for the redrive policy instead: an unparseable body is a
 * transport-level problem, and deleting it would hide a producer that has
 * started writing something this cannot read.
 */
test('an unparseable body is left on the queue for the redrive policy', function (): void {
    Queue::fake();

    scanConsumeDocument();

    fakeScanQueue([[
        'Body' => 'not json at all',
        'ReceiptHandle' => 'receipt-1',
        'MessageId' => 'msg-1',
    ]], expectDelete: false);

    $this->artisan('scan:consume', ['--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});

test('the command fails loudly when the queue is not configured', function (): void {
    config(['scanning.results_queue_url' => null]);

    $this->artisan('scan:consume', ['--once' => true])->assertFailed();
});
