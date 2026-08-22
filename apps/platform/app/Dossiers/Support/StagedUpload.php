<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

/**
 * A file the browser has already PUT to S3, verified against the object store.
 *
 * This is what replaced Illuminate\Http\UploadedFile in the upload actions.
 * The difference that matters: an UploadedFile is a local temp file PHP wrote
 * from a request body, so its size and MIME type could be measured on the spot.
 * A StagedUpload is an object already sitting in the bucket, and every field
 * here except the client's filename was read back off it by
 * ClaimUploadTicketAction — never taken from the request.
 *
 * `path` still points at the staging prefix when the value object is built.
 * The upload actions move it into the dossier tree; see
 * UploadDocumentForRequestAction.
 */
final readonly class StagedUpload
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalFilename,
        public string $mimeType,
        public int $sizeBytes,
    ) {}

    /** Lower-cased extension of the client-supplied filename, or '' when there is none. */
    public function extension(): string
    {
        $extension = pathinfo($this->originalFilename, PATHINFO_EXTENSION);

        return mb_strtolower($extension);
    }

    /** The same upload, relocated to its final resting path. */
    public function movedTo(string $path): self
    {
        return new self(
            $this->disk,
            $path,
            $this->originalFilename,
            $this->mimeType,
            $this->sizeBytes,
        );
    }
}
