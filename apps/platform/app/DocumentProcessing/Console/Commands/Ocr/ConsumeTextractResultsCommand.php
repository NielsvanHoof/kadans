<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Console\Commands\Ocr;

use App\DocumentProcessing\Jobs\ApplyTextractResultJob;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
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
use function is_numeric;
use function is_string;
use function json_decode;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Long-polls the Textract results SQS queue (SNS envelopes) and queues OCR results.
 * Not a Laravel job queue — messages are raw SNS→SQS JSON.
 *
 * This command owns the trust boundary and nothing else: verify the SNS
 * signature, pull out the inner payload, hand it to ApplyTextractResultJob and
 * delete the message. It deliberately does no work per message, because it is a
 * single process shared by every tenant — applying results inline meant one
 * large document blocked all of them, and a batch of five was processed
 * serially inside one visibility window.
 *
 * Giving up is no longer this command's decision either. Transport failures —
 * a body it cannot parse — are left visible for the queue's own redrive policy
 * to move to the DLQ (maxReceiveCount 5, infra/src/shared/documents.ts), and
 * processing failures are the job's attempt budget, ending in
 * FailTextractExtractionAction from its failed() hook.
 */
#[Signature('textract:consume {--once : Process one SQS batch then exit}')]
#[Description('Consume Textract SNS/SQS completion messages and store extracted text')]
final class ConsumeTextractResultsCommand extends Command
{
    /**
     * Long enough to parse an envelope and dispatch, short enough that a
     * consumer killed mid-message redelivers promptly.
     */
    private const int VISIBILITY_TIMEOUT_SECONDS = 30;

    public function handle(
        SqsClient $sqs,
        MessageValidator $snsMessageValidator,
        Repository $config,
        TenantContext $tenantContext,
    ): int {
        $queueUrl = $config->get('ocr.textract.results_queue_url');

        if (! is_string($queueUrl) || $queueUrl === '') {
            error('TEXTRACT_RESULTS_QUEUE_URL is not configured.');

            return self::FAILURE;
        }

        $waitTime = max(0, min(20, (int) $config->get('ocr.textract.sqs_wait_time_seconds', 20)));
        $maxMessages = max(1, min(10, (int) $config->get('ocr.textract.sqs_max_messages', 5)));
        $once = $this->option('once');

        info("Listening for Textract results on {$queueUrl}");
        Log::info('OCR: textract:consume listening for results.', [
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
                // Parse, dispatch, delete — milliseconds. It used to be 180 to
                // cover applying a whole batch inline, which let the last
                // messages of a batch reappear while still being worked.
                'VisibilityTimeout' => self::VISIBILITY_TIMEOUT_SECONDS,
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

                try {
                    $notification = $this->parseSnsEnvelope($body, $snsMessageValidator);
                } catch (InvalidSnsMessageException $exception) {
                    // Unsigned / forged envelopes will never become valid — drop them.
                    warning('Rejected Textract SQS message with an invalid SNS signature.');
                    Log::warning('OCR: textract:consume rejected SNS envelope with invalid signature.', [
                        'sqs_message_id' => $messageId,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);

                    continue;
                } catch (Throwable $exception) {
                    // Deliberately left visible rather than deleted: an
                    // unparseable body is a transport-level problem, and the
                    // queue's redrive policy is what should surface it.
                    error('Could not parse Textract SQS message: '.$exception->getMessage());
                    Log::error('OCR: textract:consume could not parse SQS message.', [
                        'sqs_message_id' => $messageId,
                        'error' => $exception->getMessage(),
                    ]);

                    continue;
                }

                $jobId = is_string($notification['JobId'] ?? null) ? $notification['JobId'] : '';

                if ($jobId === '') {
                    // Nothing downstream can match this to a document, and no
                    // number of redeliveries adds a job id to it.
                    Log::warning('OCR: textract:consume deleted a notification with no JobId.', [
                        'sqs_message_id' => $messageId,
                    ]);
                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);

                    continue;
                }

                // Dispatch before delete. The other order loses the result
                // outright if dispatch fails, whereas a delete that fails after
                // a successful dispatch only redelivers — and both the job's
                // uniqueness key and the completion action are idempotent.
                $queued = $this->dispatchResult($tenantContext, $notification, $jobId);

                if ($queued) {
                    info("Queued Textract result for job {$jobId}");
                    Log::info('OCR: textract:consume queued result and deleted SQS message.', [
                        'textract_job_id' => $jobId,
                        'sqs_message_id' => $messageId,
                        'status' => is_string($notification['Status'] ?? null) ? $notification['Status'] : null,
                    ]);
                } else {
                    // Nothing downstream can match this to a document — old SQS
                    // leftovers, or a job that was restarted under a new id.
                    Log::warning('OCR: textract:consume deleted a notification with no matching document.', [
                        'textract_job_id' => $jobId,
                        'sqs_message_id' => $messageId,
                    ]);
                }

                $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    /**
     * This command is a landlord daemon. ApplyTextractResultJob is tenant-aware
     * (`queues_are_tenant_aware_by_default`), so Spatie stores the tenant that
     * is current at dispatch and restores it before the job runs. Dispatching
     * with no tenant puts no `tenantId` on the payload; the worker then deletes
     * the job (`CurrentTenantCouldNotBeDeterminedInTenantAwareJob`) and OCR
     * never completes.
     *
     * The lookup is the only query this loop is allowed: job id → tenant id.
     * Block pagination and Bedrock stay on the `ocr` queue.
     *
     * @param  array{JobId?: mixed, Status?: mixed, StatusMessage?: mixed, API?: mixed}  $notification
     */
    private function dispatchResult(TenantContext $tenantContext, array $notification, string $jobId): bool
    {
        $tenantId = UploadedDocument::queryWithoutTenantScope()
            ->where('textract_job_id', $jobId)
            ->value('tenant_id');

        if (! is_numeric($tenantId)) {
            return false;
        }

        $tenant = Tenant::query()->find((int) $tenantId);

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $tenantContext->runForTenant($tenant, function () use ($notification, $jobId): void {
            dispatch(new ApplyTextractResultJob($notification, $jobId));
        });

        return true;
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
                throw new InvalidSnsMessageException('SNS envelope could not be verified: '.$exception->getMessage(), $exception->getCode(), previous: $exception);
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
}
