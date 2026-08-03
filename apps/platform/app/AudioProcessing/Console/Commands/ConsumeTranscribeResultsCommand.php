<?php

declare(strict_types=1);

namespace App\AudioProcessing\Console\Commands;

use App\AudioProcessing\Actions\CompleteTranscribeJobAction;
use App\AudioProcessing\Actions\FailTranscribeJobAction;
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
use function is_int;
use function is_string;
use function json_decode;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Long-polls the Amazon Transcribe results SQS queue (EventBridge payloads).
 * Not a Laravel job queue — messages are raw EventBridge JSON.
 */
#[Signature('transcribe:consume {--once : Process one SQS batch then exit}')]
#[Description('Consume Amazon Transcribe EventBridge/SQS completion messages and store transcripts')]
final class ConsumeTranscribeResultsCommand extends Command
{
    public function handle(
        SqsClient $sqs,
        CompleteTranscribeJobAction $completeTranscribeJob,
        FailTranscribeJobAction $failTranscribeJob,
        Repository $config,
    ): int {
        $queueUrl = $config->get('transcription.transcribe.results_queue_url');

        if (! is_string($queueUrl) || $queueUrl === '') {
            error('TRANSCRIBE_RESULTS_QUEUE_URL is not configured.');

            return self::FAILURE;
        }

        $waitTime = max(0, min(20, (int) $config->get('transcription.transcribe.sqs_wait_time_seconds', 20)));
        $maxMessages = max(1, min(10, (int) $config->get('transcription.transcribe.sqs_max_messages', 5)));
        $maxReceiveCount = max(1, (int) $config->get('transcription.transcribe.sqs_max_receive_count', 5));
        $once = $this->option('once');

        info("Listening for Amazon Transcribe results on {$queueUrl}");
        Log::info('Transcription: transcribe:consume listening for results.', [
            'queue_url' => $queueUrl,
            'wait_time_seconds' => $waitTime,
            'max_messages' => $maxMessages,
            'max_receive_count' => $maxReceiveCount,
            'once' => $once,
        ]);

        do {
            $result = $sqs->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => $maxMessages,
                'WaitTimeSeconds' => $waitTime,
                'VisibilityTimeout' => 180,
                'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
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
                $receiveCount = $this->receiveCount($message);
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

                $notification = null;

                try {
                    $notification = $this->parseQueuePayload($body);
                    $applied = $completeTranscribeJob->handle($notification);

                    if ($applied) {
                        $jobId = $this->jobNameFrom($notification) ?? 'unknown';
                        info("Applied Amazon Transcribe result for job {$jobId}");
                        Log::info('Transcription: transcribe:consume applied and deleted SQS message.', [
                            'transcribe_job_id' => $jobId,
                            'sqs_message_id' => $messageId,
                            'status' => $this->jobStatusFrom($notification),
                        ]);
                        $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
                    } else {
                        warning('Skipped or deferred Amazon Transcribe SQS message.');
                        Log::warning('Transcription: transcribe:consume skipped or deferred SQS message.', [
                            'transcribe_job_id' => $this->jobNameFrom($notification),
                            'sqs_message_id' => $messageId,
                            'status' => $this->jobStatusFrom($notification),
                        ]);
                    }
                } catch (Throwable $exception) {
                    error('Failed to apply Amazon Transcribe message: '.$exception->getMessage());
                    Log::error('Transcription: transcribe:consume failed to apply SQS message.', [
                        'sqs_message_id' => $messageId,
                        'receive_count' => $receiveCount,
                        'max_receive_count' => $maxReceiveCount,
                        'error' => $exception->getMessage(),
                    ]);

                    $jobId = is_array($notification) ? $this->jobNameFrom($notification) : null;

                    if (
                        $receiveCount >= $maxReceiveCount
                        && is_string($jobId)
                        && $jobId !== ''
                        && $this->failPermanently(
                            $failTranscribeJob,
                            $jobId,
                            $exception,
                            $messageId,
                        )
                    ) {
                        $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
                    }
                }
            }
        } while (! $once);

        return self::SUCCESS;
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

    /**
     * @param  array<string, mixed>  $message
     */
    private function receiveCount(array $message): int
    {
        $attributes = $message['Attributes'] ?? null;

        if (! is_array($attributes)) {
            return 1;
        }

        $receiveCount = $attributes['ApproximateReceiveCount'] ?? null;

        if (! is_int($receiveCount) && ! is_string($receiveCount)) {
            return 1;
        }

        return max(1, (int) $receiveCount);
    }

    private function failPermanently(
        FailTranscribeJobAction $failTranscribeJob,
        string $jobId,
        Throwable $exception,
        ?string $messageId,
    ): bool {
        try {
            $failed = $failTranscribeJob->handle($jobId, $exception);
        } catch (Throwable $finalizationException) {
            Log::critical('Transcription: transcribe:consume could not persist the terminal failure.', [
                'transcribe_job_id' => $jobId,
                'sqs_message_id' => $messageId,
                'error' => $finalizationException->getMessage(),
            ]);

            return false;
        }

        if (! $failed) {
            return false;
        }

        warning("Marked Amazon Transcribe job {$jobId} failed after its final receive attempt.");
        Log::error('Transcription: transcribe:consume persisted terminal failure before deleting SQS message.', [
            'transcribe_job_id' => $jobId,
            'sqs_message_id' => $messageId,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function jobNameFrom(array $notification): ?string
    {
        foreach (['JobName', 'TranscriptionJobName'] as $key) {
            $value = $notification[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function jobStatusFrom(array $notification): ?string
    {
        foreach (['Status', 'TranscriptionJobStatus'] as $key) {
            $value = $notification[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
