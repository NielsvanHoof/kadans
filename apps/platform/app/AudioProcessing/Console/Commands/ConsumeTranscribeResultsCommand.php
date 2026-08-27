<?php

declare(strict_types=1);

namespace App\AudioProcessing\Console\Commands;

use App\AudioProcessing\Data\TranscribeCompletionNotification;
use App\AudioProcessing\Jobs\ApplyTranscribeResultJob;
use App\Dossiers\Models\AudioRecording;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

/**
 * Long-polls the Amazon Transcribe results SQS queue (EventBridge payloads).
 *
 * Parses and dispatches only; ApplyTranscribeResultJob does the work. See that
 * class and its OCR mirror for why applying results inside this loop made one
 * long recording everyone's problem.
 *
 * Transport failures are left visible for the queue's redrive policy
 * (maxReceiveCount 5, infra/src/shared/documents.ts); processing failures are
 * the job's attempt budget, ending in FailTranscribeJobAction.
 */
#[Signature('transcribe:consume {--once : Process one SQS batch then exit}')]
#[Description('Consume Amazon Transcribe EventBridge/SQS completion messages and store transcripts')]
final class ConsumeTranscribeResultsCommand extends Command
{
    /** Long enough to parse and dispatch; see the OCR consumer. */
    private const int VISIBILITY_TIMEOUT_SECONDS = 30;

    public function handle(
        SqsClient $sqs,
        Repository $config,
        TenantContext $tenantContext,
    ): int {
        $queueUrl = $config->get('transcription.transcribe.results_queue_url');

        if (! is_string($queueUrl) || $queueUrl === '') {
            error('TRANSCRIBE_RESULTS_QUEUE_URL is not configured.');

            return self::FAILURE;
        }

        $waitTime = max(0, min(20, (int) $config->get('transcription.transcribe.sqs_wait_time_seconds', 20)));
        $maxMessages = max(1, min(10, (int) $config->get('transcription.transcribe.sqs_max_messages', 5)));
        $once = $this->option('once');

        info("Listening for Amazon Transcribe results on {$queueUrl}");
        Log::info('Transcription: transcribe:consume listening for results.', [
            'queue_url' => $queueUrl,
            'wait_time_seconds' => $waitTime,
            'max_messages' => $maxMessages,
            'once' => $once,
        ]);

        do {
            $result = $sqs->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => $maxMessages,
                'WaitTimeSeconds' => $waitTime,
                'VisibilityTimeout' => self::VISIBILITY_TIMEOUT_SECONDS,
            ]);

            $messages = $result->get('Messages') ?? [];

            if (! is_array($messages) || $messages === []) {
                if ($once) {
                    break;
                }

                continue;
            }

            Log::info('Transcription: transcribe:consume received SQS batch.', [
                'message_count' => count($messages),
            ]);

            foreach ($messages as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $receiptHandle = $message['ReceiptHandle'] ?? null;
                $body = $message['Body'] ?? null;
                $messageId = is_string($message['MessageId'] ?? null) ? $message['MessageId'] : null;

                if (! is_string($receiptHandle)) {
                    continue;
                }
                if ($receiptHandle === '') {
                    continue;
                }

                if (! is_string($body) || $body === '') {
                    Log::warning('Transcription: transcribe:consume deleted empty SQS body.', [
                        'sqs_message_id' => $messageId,
                    ]);
                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);

                    continue;
                }

                try {
                    $notification = $this->parseQueuePayload($body);
                    // Same parse the action performs, so the two can no longer
                    // disagree about a job name or a status's casing.
                    $completion = TranscribeCompletionNotification::fromEventBridgeDetail($notification);
                } catch (Throwable $exception) {
                    // Left visible rather than deleted: an unparseable body is a
                    // transport-level problem for the redrive policy to surface.
                    error('Could not parse Amazon Transcribe SQS message: '.$exception->getMessage());
                    Log::error('Transcription: transcribe:consume could not parse SQS message.', [
                        'sqs_message_id' => $messageId,
                        'error' => $exception->getMessage(),
                    ]);

                    continue;
                }

                $jobId = $completion->jobId;

                if (! is_string($jobId) || $jobId === '') {
                    // No job name means nothing downstream can match a recording,
                    // and redelivery will not add one.
                    Log::warning('Transcription: transcribe:consume deleted a notification with no job name.', [
                        'sqs_message_id' => $messageId,
                    ]);
                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);

                    continue;
                }

                $queued = $this->dispatchResult($tenantContext, $notification, $jobId);

                if ($queued) {
                    info("Queued Amazon Transcribe result for job {$jobId}");
                    Log::info('Transcription: transcribe:consume queued result and deleted SQS message.', [
                        'transcribe_job_id' => $jobId,
                        'sqs_message_id' => $messageId,
                        'status' => $completion->status,
                    ]);
                } else {
                    Log::warning('Transcription: transcribe:consume deleted a notification with no matching recording.', [
                        'transcribe_job_id' => $jobId,
                        'sqs_message_id' => $messageId,
                    ]);
                }

                $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    /**
     * Same landlord-daemon constraint as the OCR consumer: the job is
     * tenant-aware, so the tenant must be current at dispatch or Spatie
     * deletes it on the worker. Lookup is job name → tenant id only.
     *
     * @param  array<string, mixed>  $notification
     */
    private function dispatchResult(TenantContext $tenantContext, array $notification, string $jobId): bool
    {
        $tenantId = AudioRecording::queryWithoutTenantScope()
            ->where('transcribe_job_id', $jobId)
            ->value('tenant_id');

        if (! is_numeric($tenantId)) {
            return false;
        }

        $tenant = Tenant::query()->find((int) $tenantId);

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $tenantContext->runForTenant($tenant, function () use ($notification, $jobId): void {
            dispatch(new ApplyTranscribeResultJob($notification, $jobId));
        });

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseQueuePayload(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonException('Invalid SQS body JSON: '.$exception->getMessage(), 0, $exception);
        }

        throw_unless(is_array($decoded), JsonException::class, 'SQS body must be a JSON object.');

        // EventBridge → SQS delivers the full event; job fields live under detail.
        throw_unless(
            ($decoded['source'] ?? null) === 'aws.transcribe' && is_array($decoded['detail'] ?? null),
            JsonException::class,
            'SQS body must be an aws.transcribe EventBridge event with a detail object.',
        );

        /** @var array<string, mixed> $detail */
        $detail = $decoded['detail'];

        return $detail;
    }

    private function deleteMessage(SqsClient $sqs, string $queueUrl, string $receiptHandle): void
    {
        $sqs->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }
}
