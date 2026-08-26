<?php

declare(strict_types=1);

use App\AudioProcessing\Console\Commands\ConsumeTranscribeResultsCommand;
use App\AudioProcessing\Jobs\ApplyTranscribeResultJob;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

covers(ConsumeTranscribeResultsCommand::class);

/**
 * This command had no test of any kind, which is how it kept a copy of the
 * job-name/status parsing that had quietly diverged from the action's — the
 * action upper-cased the status, this one did not. Both now go through
 * TranscribeCompletionNotification, and this pins the envelope contract that
 * makes that safe.
 */
function transcribeConsumeRecording(string $jobId): AudioRecording
{
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);

    $path = sprintf('tenants/%d/dossiers/%d/audio/call.m4a', $tenant->id, $dossier->id);
    Storage::disk('local')->put($path, 'fake-audio');

    return AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 'local',
        'path' => $path,
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => $jobId,
    ]);
}

/**
 * EventBridge → SQS delivers the whole event; the job fields live under detail.
 *
 * @param  array<string, mixed>  $detail
 */
function eventBridgeBody(array $detail): string
{
    return json_encode([
        'source' => 'aws.transcribe',
        'detail-type' => 'Transcribe Job State Change',
        'detail' => $detail,
    ], JSON_THROW_ON_ERROR);
}

/**
 * @param  list<array<string, string>>  $messages
 */
function fakeTranscribeQueue(array $messages, bool $expectDelete): SqsClient
{
    Storage::fake('local');

    config([
        'transcription.transcribe.results_queue_url' => 'https://sqs.eu-west-1.amazonaws.com/123/transcribe-results',
        'transcription.transcribe.sqs_wait_time_seconds' => 0,
        'transcription.transcribe.sqs_max_messages' => 1,
    ]);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')->once()->andReturn(new Result(['Messages' => $messages]));

    if ($expectDelete) {
        $sqs->shouldReceive('deleteMessage')->once()->andReturn(new Result([]));
    } else {
        $sqs->shouldReceive('deleteMessage')->never();
    }

    app()->instance(SqsClient::class, $sqs);

    return $sqs;
}

test('a failed transcribe event is applied and its message deleted', function (): void {
    $recording = transcribeConsumeRecording('transcribe-job-1');

    fakeTranscribeQueue([[
        'Body' => eventBridgeBody([
            'TranscriptionJobName' => 'transcribe-job-1',
            'TranscriptionJobStatus' => 'FAILED',
            'FailureReason' => 'The media format is not supported.',
        ]),
        'ReceiptHandle' => 'receipt-1',
        'MessageId' => 'msg-1',
    ]], expectDelete: true);

    $this->artisan('transcribe:consume', ['--once' => true])->assertSuccessful();

    $recording->refresh();

    expect($recording->transcription_status)->toBe(TranscriptionStatus::Failed)
        ->and($recording->transcription_error)->toBe('The media format is not supported.');
});

/**
 * The casing the two copies disagreed on. AWS sends upper case today, so a
 * lower-case status only ever reached the divergent copy in a log line — but a
 * status that is not normalised is a terminal state that never registers.
 */
test('a lower-case status is still recognised as terminal', function (): void {
    $recording = transcribeConsumeRecording('transcribe-job-2');

    fakeTranscribeQueue([[
        'Body' => eventBridgeBody([
            'JobName' => 'transcribe-job-2',
            'Status' => 'failed',
            'FailureReason' => 'Provider reported a lower-case status.',
        ]),
        'ReceiptHandle' => 'receipt-2',
        'MessageId' => 'msg-2',
    ]], expectDelete: true);

    $this->artisan('transcribe:consume', ['--once' => true])->assertSuccessful();

    expect($recording->refresh()->transcription_status)->toBe(TranscriptionStatus::Failed);
});

test('an envelope that is not an aws.transcribe event is left on the queue', function (): void {
    $recording = transcribeConsumeRecording('transcribe-job-3');

    fakeTranscribeQueue([[
        'Body' => json_encode(['source' => 'aws.s3', 'detail' => []], JSON_THROW_ON_ERROR),
        'ReceiptHandle' => 'receipt-3',
        'MessageId' => 'msg-3',
    ]], expectDelete: false);

    $this->artisan('transcribe:consume', ['--once' => true])->assertSuccessful();

    // Untouched: a message we cannot understand must not silently vanish.
    expect($recording->refresh()->transcription_status)->toBe(TranscriptionStatus::Processing);
});

test('the transcribe consumer queues the result instead of fetching it inline', function (): void {
    Queue::fake();

    transcribeConsumeRecording('transcribe-job-queued');

    fakeTranscribeQueue([[
        'Body' => eventBridgeBody([
            'TranscriptionJobName' => 'transcribe-job-queued',
            'TranscriptionJobStatus' => 'COMPLETED',
        ]),
        'ReceiptHandle' => 'receipt-queued',
    ]], expectDelete: true);

    $this->artisan('transcribe:consume', ['--once' => true])->assertSuccessful();

    // The transcript fetch and segment mapping belong on the transcription
    // queue, not on a poll loop shared by every tenant.
    Queue::assertPushed(
        ApplyTranscribeResultJob::class,
        fn (ApplyTranscribeResultJob $job): bool => $job->transcribeJobId === 'transcribe-job-queued'
            && $job->queue === 'transcription',
    );
});

test('an event carrying no job name is dropped rather than queued', function (): void {
    Queue::fake();

    transcribeConsumeRecording('transcribe-job-live');

    fakeTranscribeQueue([[
        'Body' => eventBridgeBody(['TranscriptionJobStatus' => 'COMPLETED']),
        'ReceiptHandle' => 'receipt-nameless',
    ]], expectDelete: true);

    $this->artisan('transcribe:consume', ['--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});
