<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Uploads;

use App\Dossiers\Data\UploadTicketData;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Support\DocumentPresigner;
use App\Dossiers\Support\UploadTicket;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function in_array;
use function pathinfo;
use function sprintf;

/**
 * Mint a presigned PUT plus the ticket that redeems it.
 *
 * The ticket is the security boundary. A presigned URL on its own only proves
 * the browser was allowed to write *somewhere*; without a ticket the follow-up
 * request would have to name the object key, and a client that names its own
 * key can name a key it was never given. So the key is minted here, sealed
 * into an encrypted payload the client cannot read or forge, and re-opened by
 * ClaimUploadTicketAction.
 *
 * The size and content type passed in are the browser's claims about a file it
 * has not uploaded yet. They are checked here only to fail fast and cheaply —
 * the check that counts runs at claim time against the stored object.
 */
final readonly class IssueUploadTicketAction
{
    /**
     * Long enough for a 25 MB file on a poor connection, short enough that a
     * leaked URL is worth little. The ticket carries the same deadline, so a
     * stale one cannot be redeemed even if the object somehow lands.
     */
    private const int TICKET_LIFETIME_MINUTES = 30;

    public function __construct(private DocumentPresigner $presigner) {}

    public function handle(
        UploadPurpose $purpose,
        int $tenantId,
        string $filename,
        string $contentType,
        int $declaredSizeBytes,
        string $errorKey,
    ): UploadTicketData {
        $disk = Config::string('filesystems.default', 'local');

        throw_unless(
            $this->presigner->supports($disk),
            ValidationException::withMessages([
                $errorKey => __('Direct uploads are not available on this environment.'),
            ]),
        );

        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, $purpose->allowedExtensions(), true)) {
            throw ValidationException::withMessages([
                $errorKey => $purpose->unsupportedTypeMessage(),
            ]);
        }

        if ($declaredSizeBytes > $purpose->maxBytes()) {
            throw ValidationException::withMessages([
                $errorKey => __('That file is too large. The maximum size is :max.', [
                    'max' => $purpose->maxSizeLabel(),
                ]),
            ]);
        }

        /*
         * Staged, not final. The dossier tree only ever receives objects that
         * have been claimed and checked, and anything left here is
         * unambiguously abandoned — which is what makes the bucket's staging/
         * expiry rule safe to apply (infra/src/shared/documents.ts).
         *
         * The key carries no client-supplied text: the original filename is
         * kept in the ticket and written to the database, not into the path.
         */
        $path = sprintf('staging/tenants/%d/%s.%s', $tenantId, (string) Str::ulid(), $extension);
        $expiresAt = now()->addMinutes(self::TICKET_LIFETIME_MINUTES);

        $ticket = new UploadTicket(
            purpose: $purpose,
            tenantId: $tenantId,
            disk: $disk,
            path: $path,
            filename: $filename,
            expiresAt: $expiresAt,
        );

        return new UploadTicketData(
            uploadUrl: $this->presigner->presignPut($disk, $path, $contentType, $expiresAt),
            ticket: $ticket->seal(),
            contentType: $contentType,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }
}
