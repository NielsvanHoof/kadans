<?php

declare(strict_types=1);

namespace App\Dossiers\Data\Scanning;

use App\Dossiers\Enums\MalwareScanStatus;
use Carbon\CarbonImmutable;
use Throwable;

use function is_string;

/**
 * One malware scan verdict from the document-scan Lambda, parsed at the trust
 * boundary.
 *
 * The producer is `apps/lambdas/document-scan`, and this class is the
 * application's half of the wire format it writes. The message is not signed —
 * the queue policy and IAM are the control — so every field is untrusted input
 * that may be missing or the wrong type.
 *
 * The status deliberately uses GuardDuty's own vocabulary, which is what
 * MalwareScanStatus already holds, so a verdict from the queue and a verdict
 * read off an object tag parse into the same enum. Keep the two in step: the Go
 * side pins the same five values in internal/scanevent.
 *
 * Internal value object rather than a Spatie Data DTO, per
 * .ai/rules/requests-enums-policies.md — nothing here is ever sent to a client.
 */
final readonly class ScanResultNotification
{
    private function __construct(
        public string $key,
        public ?MalwareScanStatus $status,
        public ?string $thumbnailKey,
        public ?CarbonImmutable $scannedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public static function fromQueueMessage(array $message): self
    {
        return new self(
            key: self::string($message, 'key') ?? '',
            /*
             * Null for a value this application does not recognise, which is
             * treated the same as any other non-clean verdict. The Lambda
             * already collapses an unknown GuardDuty status to FAILED, so this
             * is the second line rather than the first.
             */
            status: MalwareScanStatus::tryFrom(self::string($message, 'status') ?? ''),
            /*
             * Absent rather than empty when there is no thumbnail, which is the
             * ordinary case for a non-clean verdict, an unrenderable format, or
             * a render that failed. None of those should hold up the verdict.
             */
            thumbnailKey: self::string($message, 'thumbnailKey'),
            scannedAt: self::timestamp($message, 'scannedAt'),
        );
    }

    /**
     * Whether this message names an object at all.
     *
     * A message without a key cannot be matched to anything, and redelivering
     * it will not add one — the caller deletes it rather than letting the
     * redrive policy retry it five times first.
     */
    public function namesAnObject(): bool
    {
        return $this->key !== '';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function string(array $message, string $field): ?string
    {
        $value = $message[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function timestamp(array $message, string $field): ?CarbonImmutable
    {
        $value = self::string($message, $field);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            // An unparseable timestamp is not worth losing the verdict over;
            // the caller falls back to the moment it was applied.
            return null;
        }
    }
}
