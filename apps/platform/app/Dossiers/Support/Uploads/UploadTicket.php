<?php

declare(strict_types=1);

namespace App\Dossiers\Support\Uploads;

use App\Dossiers\Enums\UploadPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

use function is_array;
use function is_int;
use function is_string;

/**
 * The sealed claim slip a browser gets when it is allowed to upload.
 *
 * Sealing lives here rather than in the issuing action so there is exactly one
 * definition of what a ticket contains and how it is verified. A second copy —
 * in a test helper, say — would be a copy that can drift from the real one
 * while every test still passes.
 *
 * `Crypt` is authenticated encryption over APP_KEY, so a client can neither
 * read the object key nor alter the tenant, purpose or deadline. That is the
 * whole security model: presigned PUTs let the browser write to the bucket, and
 * this is what stops it deciding *where*.
 */
final readonly class UploadTicket
{
    public function __construct(
        public UploadPurpose $purpose,
        public int $tenantId,
        public string $disk,
        public string $path,
        public string $filename,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * Open a sealed ticket, or null when it is unreadable or malformed.
     *
     * Every failure returns null rather than throwing a distinguishable error:
     * the caller turns them all into one message, so a prober cannot learn
     * which part of a forged ticket was almost right.
     */
    public static function open(string $sealed): ?self
    {
        try {
            $decoded = json_decode(Crypt::decryptString($sealed), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (
            ! is_array($decoded)
            || ! is_string($decoded['purpose'] ?? null)
            || ! is_int($decoded['tenant_id'] ?? null)
            || ! is_string($decoded['disk'] ?? null)
            || ! is_string($decoded['path'] ?? null)
            || ! is_string($decoded['filename'] ?? null)
            || ! is_int($decoded['expires_at'] ?? null)
        ) {
            return null;
        }

        $purpose = UploadPurpose::tryFrom($decoded['purpose']);

        if (! $purpose instanceof UploadPurpose) {
            return null;
        }

        return new self(
            purpose: $purpose,
            tenantId: $decoded['tenant_id'],
            disk: $decoded['disk'],
            path: $decoded['path'],
            filename: $decoded['filename'],
            expiresAt: CarbonImmutable::createFromTimestamp($decoded['expires_at']),
        );
    }

    public function seal(): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => $this->purpose->value,
            'tenant_id' => $this->tenantId,
            'disk' => $this->disk,
            'path' => $this->path,
            'filename' => $this->filename,
            'expires_at' => $this->expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public function isUsableFor(UploadPurpose $purpose, int $tenantId): bool
    {
        return $this->purpose === $purpose
            && $this->tenantId === $tenantId
            && $this->expiresAt->isFuture();
    }
}
