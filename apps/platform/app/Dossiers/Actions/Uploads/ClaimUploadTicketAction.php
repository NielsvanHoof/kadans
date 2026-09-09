<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Uploads;

use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Support\Uploads\DocumentPresigner;
use App\Dossiers\Support\Uploads\StagedUpload;
use App\Dossiers\Support\Uploads\UploadTicket;
use Illuminate\Validation\ValidationException;

use function in_array;

/**
 * Redeem an upload ticket for a verified StagedUpload.
 *
 * Everything a client can influence is re-derived here rather than trusted:
 *
 * - the object key comes out of the sealed ticket, never the request;
 * - the tenant on the ticket must match the tenant of the thing being uploaded
 *   to, so a ticket minted in one workspace cannot be spent in another;
 * - the size and content type are read back off the stored object, because the
 *   values quoted at issue time were a promise about a file that had not been
 *   sent yet.
 *
 * The specific gap this closes: the browser now writes to the bucket directly,
 * so between issue and claim there is a window where the client controls the
 * bytes and no PHP process has seen them.
 */
final readonly class ClaimUploadTicketAction
{
    public function __construct(private DocumentPresigner $presigner) {}

    public function handle(
        string $sealedTicket,
        UploadPurpose $expectedPurpose,
        int $expectedTenantId,
        string $errorKey,
    ): StagedUpload {
        $ticket = UploadTicket::open($sealedTicket);

        if (! $ticket instanceof UploadTicket || ! $ticket->isUsableFor($expectedPurpose, $expectedTenantId)) {
            throw $this->rejected($errorKey);
        }

        $object = $this->presigner->storedObject($ticket->disk, $ticket->path);

        if ($object === null) {
            throw ValidationException::withMessages([
                $errorKey => __('That file did not finish uploading. Try again.'),
            ]);
        }

        /*
         * An empty object is what is left when a PUT is interrupted mid-flight,
         * and it is indistinguishable from a successful upload downstream — an
         * OCR run would be queued for nothing and the client would be told the
         * document was received.
         */
        if ($object['size'] === 0) {
            throw ValidationException::withMessages([
                $errorKey => __('That file is empty.'),
            ]);
        }

        if ($object['size'] > $expectedPurpose->maxBytes()) {
            throw ValidationException::withMessages([
                $errorKey => __('That file is too large. The maximum size is :max.', [
                    'max' => $expectedPurpose->maxSizeLabel(),
                ]),
            ]);
        }

        $extension = mb_strtolower(pathinfo($ticket->filename, PATHINFO_EXTENSION));

        /*
         * The extension was checked when the ticket was issued and it travels
         * sealed, so this can only fail if that check was wrong. It costs one
         * array lookup and it is the last gate before an arbitrary file enters
         * the dossier tree.
         */
        if (! in_array($extension, $expectedPurpose->allowedExtensions(), true)) {
            throw ValidationException::withMessages([
                $errorKey => $expectedPurpose->unsupportedTypeMessage(),
            ]);
        }

        return new StagedUpload(
            disk: $ticket->disk,
            path: $ticket->path,
            originalFilename: $ticket->filename,
            // From the extension, not from $object['contentType']. A presigned
            // PUT signs only `host`, so the Content-Type S3 recorded is
            // whatever the browser chose to send.
            mimeType: $expectedPurpose->mimeTypeForExtension($extension),
            sizeBytes: $object['size'],
        );
    }

    /**
     * One message for every way a ticket can be unusable — forged, expired,
     * for the wrong tenant, for the wrong purpose. Distinguishing them would
     * tell a prober which part of a forged ticket they got right.
     */
    private function rejected(string $errorKey): ValidationException
    {
        return ValidationException::withMessages([
            $errorKey => __('That upload is no longer valid. Choose the file again.'),
        ]);
    }
}
