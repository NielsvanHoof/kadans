<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Console\Commands\Ocr;

use App\DocumentProcessing\Actions\Ocr\CompleteTextractExtractionAction;
use App\DocumentProcessing\Actions\Ocr\FailTextractExtractionAction;
use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
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
 * Long-polls the Textract results SQS queue (SNS envelopes) and applies OCR results.
 * Not a Laravel job queue — messages are raw SNS→SQS JSON.
 */
#[Signature('textract:consume {--once : Process one SQS batch then exit}')]
#[Description('Consume Textract SNS/SQS completion messages and store extracted text')]
final class ConsumeTextractResultsCommand extends Command
{
    public function handle(
        SqsClient $sqs,
        CompleteTextractExtractionAction $completeTextractExtraction,
        FailTextractExtractionAction $failTextractExtraction,
        MessageValidator $snsMessageValidator,
        Repository $config,
    ): int {
        $queueUrl = $config->get('ocr.textract.results_queue_url');

        if (! is_string($queueUrl) || $queueUrl === '') {
            error('TEXTRACT_RESULTS_QUEUE_URL is not configured.');

            return self::FAILURE;
        }

        $waitTime = max(0, min(20, (int) $config->get('ocr.textract.sqs_wait_time_seconds', 20)));
        $maxMessages = max(1, min(10, (int) $config->get('ocr.textract.sqs_max_messages', 5)));
        $maxReceiveCount = max(1, (int) $config->get('ocr.textract.sqs_max_receive_count', 5));
        $once = $this->option('once');

        info("Listening for Textract results on {$queueUrl}");
        Log::info('OCR: textract:consume listening for results.', [
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

            Log::info('OCR: textract:consume received SQS batch.', [
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
                    Log::warning('OCR: textract:consume deleted empty SQS body.', [
                        'sqs_message_id' => $messageId,
                    ]);
                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);

                    continue;
                }

                $notification = null;

                try {
                    $notification = $this->parseSnsEnvelope($body, $snsMessageValidator);
                    $applied = $completeTextractExtraction->handle($notification);

                    if ($applied) {
                        $jobId = is_string($notification['JobId'] ?? null)
                            ? $notification['JobId']
                            : 'unknown';
                        info("Applied Textract result for job {$jobId}");
                        Log::info('OCR: textract:consume applied and deleted SQS message.', [
                            'textract_job_id' => $jobId,
                            'sqs_message_id' => $messageId,
                            'status' => is_string($notification['Status'] ?? null)
                                ? $notification['Status']
                                : null,
                        ]);
                        $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
                    } else {
                        // Leave non-final statuses visible so another poll can retry.
                        warning('Skipped or deferred Textract SQS message.');
                        Log::warning('OCR: textract:consume skipped or deferred SQS message.', [
                            'textract_job_id' => is_string($notification['JobId'] ?? null)
                                ? $notification['JobId']
                                : null,
                            'sqs_message_id' => $messageId,
                            'status' => is_string($notification['Status'] ?? null)
                                ? $notification['Status']
                                : null,
                        ]);
                    }
                } catch (InvalidSnsMessageException $exception) {
                    // Unsigned / forged envelopes will never become valid — drop them.
                    warning('Rejected Textract SQS message with an invalid SNS signature.');
                    Log::warning('OCR: textract:consume rejected SNS envelope with invalid signature.', [
                        'sqs_message_id' => $messageId,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
                } catch (Throwable $exception) {
                    error('Failed to apply Textract message: '.$exception->getMessage());
                    Log::error('OCR: textract:consume failed to apply SQS message.', [
                        'sqs_message_id' => $messageId,
                        'receive_count' => $receiveCount,
                        'max_receive_count' => $maxReceiveCount,
                        'error' => $exception->getMessage(),
                    ]);

                    $jobId = is_array($notification) && is_string($notification['JobId'] ?? null)
                        ? $notification['JobId']
                        : null;

                    if (
                        $receiveCount >= $maxReceiveCount
                        && is_string($jobId)
                        && $jobId !== ''
                        && $this->failPermanently(
                            $failTextractExtraction,
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
     * SNS → SQS wraps the Textract payload in a Message string.
     * Signature is verified before the inner JSON is trusted.
     *
     * @return array{JobId?: mixed, Status?: mixed, StatusMessage?: mixed, API?: mixed}
     */
    private function parseSnsEnvelope(string $body, MessageValidator $snsMessageValidator): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonException('Invalid SQS body JSON: '.$exception->getMessage(), 0, $exception);
        }

        throw_unless(is_array($decoded), JsonException::class, 'SQS body must be a JSON object.');

        // SNS→SQS notifications carry Type + Signature. Unsigned SNS-shaped
        // bodies (string Message without a verifiable signature) are rejected.
        $looksLikeSnsEnvelope = isset($decoded['Type'])
            || isset($decoded['Signature'])
            || is_string($decoded['Message'] ?? null);

        if ($looksLikeSnsEnvelope) {
            try {
                $snsMessageValidator->validate(Message::fromJsonString($body));
            } catch (InvalidSnsMessageException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                // Incomplete SNS fields fail before openssl verification.
                throw new InvalidSnsMessageException(
                    'SNS envelope could not be verified: '.$exception->getMessage(),
                    previous: $exception,
                );
            }
        }

        $inner = $decoded['Message'] ?? $decoded;

        if (is_string($inner)) {
            try {
                /** @var mixed $innerDecoded */
                $innerDecoded = json_decode($inner, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new JsonException('Invalid SNS Message JSON: '.$exception->getMessage(), 0, $exception);
            }

            throw_unless(is_array($innerDecoded), JsonException::class, 'SNS Message must be a JSON object.');

            /** @var array{JobId?: mixed, Status?: mixed, StatusMessage?: mixed, API?: mixed} $innerDecoded */
            return $innerDecoded;
        }

        throw_unless(is_array($inner), JsonException::class, 'Unexpected SNS/SQS payload shape.');

        /** @var array{JobId?: mixed, Status?: mixed, StatusMessage?: mixed, API?: mixed} $inner */
        return $inner;
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
        FailTextractExtractionAction $failTextractExtraction,
        string $jobId,
        Throwable $exception,
        ?string $messageId,
    ): bool {
        try {
            $failed = $failTextractExtraction->handle($jobId, $exception);
        } catch (Throwable $finalizationException) {
            Log::critical('OCR: textract:consume could not persist the terminal failure.', [
                'textract_job_id' => $jobId,
                'sqs_message_id' => $messageId,
                'error' => $finalizationException->getMessage(),
            ]);

            return false;
        }

        if (! $failed) {
            return false;
        }

        warning("Marked Textract job {$jobId} failed after its final receive attempt.");
        Log::error('OCR: textract:consume persisted terminal failure before deleting SQS message.', [
            'textract_job_id' => $jobId,
            'sqs_message_id' => $messageId,
        ]);

        return true;
    }
}
