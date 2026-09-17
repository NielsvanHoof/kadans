<?php

declare(strict_types=1);

namespace App\Dossiers\Console\Commands\Scanning;

use App\Dossiers\Data\Scanning\ScanResultNotification;
use App\Dossiers\Jobs\Scanning\ApplyScanResultJob;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\UploadedDocument;
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
 * Long-polls the malware scan results queue written by the document-scan Lambda.
 *
 * The third landlord daemon, alongside `textract:consume` and
 * `transcribe:consume`, and built the same way for the same reasons: it parses
 * and dispatches only. The work is trivial here — one update — but the shape is
 * worth keeping, because a loop that does its work inline is a loop where one
 * slow write delays every other tenant's messages.
 *
 * The tenant trap is the part to be careful about. ApplyScanResultJob is
 * tenant-aware (`queues_are_tenant_aware_by_default`), so the tenant must be
 * current at dispatch; dispatching without one puts no tenantId on the payload
 * and Spatie deletes the job on the worker, so the verdict would simply never
 * be applied and nothing would say so. The lookup below is the only query this
 * loop performs.
 *
 * Transport failures are left visible for the queue's redrive policy; a message
 * that can never be acted on is deleted rather than redelivered five times
 * first.
 */
#[Signature('scan:consume {--once : Process one SQS batch then exit}')]
#[Description('Consume malware scan verdicts and thumbnail keys from the document-scan Lambda')]
final class ConsumeScanResultsCommand extends Command
{
    /** Long enough to parse and dispatch; see the OCR consumer. */
    private const int VISIBILITY_TIMEOUT_SECONDS = 30;

    public function handle(
        SqsClient $sqs,
        Repository $config,
        TenantContext $tenantContext,
    ): int {
        $queueUrl = $config->get('scanning.results_queue_url');

        if (! is_string($queueUrl) || $queueUrl === '') {
            error('SCAN_RESULTS_QUEUE_URL is not configured.');

            return self::FAILURE;
        }

        $waitTime = max(0, min(20, (int) $config->get('scanning.sqs_wait_time_seconds', 20)));
        $maxMessages = max(1, min(10, (int) $config->get('scanning.sqs_max_messages', 5)));
        $once = $this->option('once');

        info("Listening for malware scan verdicts on {$queueUrl}");
        Log::info('Scanning: scan:consume listening for verdicts.', [
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

            Log::info('Scanning: scan:consume received SQS batch.', [
                'message_count' => count($messages),
            ]);

            foreach ($messages as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $receiptHandle = $message['ReceiptHandle'] ?? null;
                $body = $message['Body'] ?? null;
                $messageId = is_string($message['MessageId'] ?? null) ? $message['MessageId'] : null;

                if (! is_string($receiptHandle) || $receiptHandle === '') {
                    continue;
                }

                if (! is_string($body) || $body === '') {
                    Log::warning('Scanning: scan:consume deleted empty SQS body.', [
                        'sqs_message_id' => $messageId,
                    ]);
                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);

                    continue;
                }

                try {
                    $payload = $this->parseQueuePayload($body);
                    // Same parse the action performs, so the two cannot disagree
                    // about a field name or a status's casing.
                    $notification = ScanResultNotification::fromQueueMessage($payload);
                } catch (Throwable $exception) {
                    // Left visible rather than deleted: an unparseable body is a
                    // transport-level problem for the redrive policy to surface.
                    error('Could not parse malware scan verdict: '.$exception->getMessage());
                    Log::error('Scanning: scan:consume could not parse SQS message.', [
                        'sqs_message_id' => $messageId,
                        'error' => $exception->getMessage(),
                    ]);

                    continue;
                }

                if (! $notification->namesAnObject()) {
                    // No key means nothing downstream can match a file, and
                    // redelivery will not add one.
                    Log::warning('Scanning: scan:consume deleted a verdict with no object key.', [
                        'sqs_message_id' => $messageId,
                    ]);
                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);

                    continue;
                }

                $queued = $this->dispatchResult($tenantContext, $payload, $notification->key);

                if ($queued) {
                    Log::info('Scanning: scan:consume queued verdict and deleted SQS message.', [
                        'sqs_message_id' => $messageId,
                        'status' => $notification->status?->value,
                        'has_thumbnail' => $notification->thumbnailKey !== null,
                    ]);
                } else {
                    Log::warning('Scanning: scan:consume deleted a verdict with no matching file.', [
                        'sqs_message_id' => $messageId,
                    ]);
                }

                $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    /**
     * Resolve the owning tenant and dispatch inside it.
     *
     * The object key does contain a tenant id — the Lambda only acts on
     * `tenants/{id}/…` keys — but it arrives from an AWS event and is not read
     * for that. The owning row is looked up instead, so a malformed or crafted
     * key selects nothing rather than selecting the wrong firm.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatchResult(TenantContext $tenantContext, array $payload, string $key): bool
    {
        $tenantId = UploadedDocument::queryWithoutTenantScope()
            ->where('path', $key)
            ->value('tenant_id');

        if (! is_numeric($tenantId)) {
            $tenantId = AudioRecording::queryWithoutTenantScope()
                ->where('path', $key)
                ->value('tenant_id');
        }

        if (! is_numeric($tenantId)) {
            return false;
        }

        $tenant = Tenant::query()->find((int) $tenantId);

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $tenantContext->runForTenant($tenant, function () use ($payload, $key): void {
            dispatch(new ApplyScanResultJob($payload, $key));
        });

        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function parseQueuePayload(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        throw_unless(is_array($decoded), JsonException::class, 'Scan verdict body is not an object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function deleteMessage(SqsClient $sqs, string $queueUrl, string $receiptHandle): void
    {
        $sqs->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }
}
