<?php

declare(strict_types=1);

namespace App\AudioProcessing\Data;

use function in_array;
use function is_string;
use function mb_strtoupper;
use function mb_substr;

/**
 * One Amazon Transcribe completion, parsed at the trust boundary.
 *
 * The envelope arrives from EventBridge via SQS and is not signed — the queue
 * policy is the control — so every field here is untrusted input that may be
 * absent or the wrong type. AWS uses `TranscriptionJobName` /
 * `TranscriptionJobStatus`; the shorter `JobName` / `Status` aliases are also
 * accepted, which is why reading a field is not a single array lookup.
 *
 * This exists because that parsing lived twice: byte-identically in
 * CompleteTranscribeJobAction and ConsumeTranscribeResultsCommand for the job
 * name, and ALMOST identically for the status — the action upper-cased it, the
 * command did not. The command only logged its copy, so the divergence was
 * invisible; the moment anyone used it for a decision, a lower-case
 * "completed" would have missed the terminal check and the job would have
 * looked non-terminal forever.
 *
 * Internal value object, never sent to the client, so a plain final readonly
 * class rather than a Spatie Data DTO (.ai/rules/requests-enums-policies.md).
 * Deliberately AudioProcessing-local: the Textract envelope is SNS-signed with
 * a different shape, and a shared "provider completion" type is exactly what
 * .ai/rules/document-processing-audio-processing.md forbids.
 */
final readonly class TranscribeCompletionNotification
{
    /** Terminal success states, upper-cased. */
    private const array COMPLETED_STATUSES = ['COMPLETED', 'SUCCEEDED'];

    /** Terminal failure states, upper-cased. */
    private const array FAILED_STATUSES = ['FAILED', 'ERROR'];

    private const int MAX_FAILURE_REASON_LENGTH = 500;

    private function __construct(
        public ?string $jobId,
        public ?string $status,
        private ?string $failureReason,
    ) {}

    /**
     * @param  array<string, mixed>  $notification
     */
    public static function fromEventBridgeDetail(array $notification): self
    {
        return new self(
            jobId: self::firstNonEmptyString($notification, ['JobName', 'TranscriptionJobName']),
            // Upper-cased once, here, so every caller compares the same thing.
            status: self::firstNonEmptyString($notification, ['Status', 'TranscriptionJobStatus'], upperCase: true),
            failureReason: self::firstNonEmptyString($notification, ['FailureReason']),
        );
    }

    public function isCompleted(): bool
    {
        return $this->status !== null && in_array($this->status, self::COMPLETED_STATUSES, true);
    }

    public function isFailed(): bool
    {
        return $this->status !== null && in_array($this->status, self::FAILED_STATUSES, true);
    }

    /**
     * The provider's reason, or a stated fallback naming the status we saw.
     *
     * Bounded to the column width here rather than at each call site.
     */
    public function failureReason(): string
    {
        $fallback = "Amazon Transcribe job reported {$this->status}.";

        if ($this->failureReason === null) {
            return $fallback;
        }

        return mb_substr($this->failureReason, 0, self::MAX_FAILURE_REASON_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $notification
     * @param  list<string>  $keys
     */
    private static function firstNonEmptyString(array $notification, array $keys, bool $upperCase = false): ?string
    {
        foreach ($keys as $key) {
            $value = $notification[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $upperCase ? mb_strtoupper($value) : $value;
            }
        }

        return null;
    }
}
