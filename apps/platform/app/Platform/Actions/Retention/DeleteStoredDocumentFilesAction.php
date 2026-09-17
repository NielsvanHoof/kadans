<?php

declare(strict_types=1);

namespace App\Platform\Actions\Retention;

use App\Dossiers\Models\UploadedDocument;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final readonly class DeleteStoredDocumentFilesAction
{
    public function __construct(private FilesystemManager $filesystems) {}

    /**
     * @param  Collection<int, UploadedDocument>  $uploadedDocuments
     */
    public function handle(Collection $uploadedDocuments): void
    {
        foreach ($uploadedDocuments as $uploadedDocument) {
            $this->deleteOrFail($uploadedDocument->disk, $uploadedDocument->path);

            /*
             * The rendered preview is a picture of the first page, so a purge
             * that removed the document and left the thumbnail would leave the
             * client's content in the bucket under a different key. A legal
             * purge that half-succeeds is the failure this whole action exists
             * to make loud, so this fails the same way the document does.
             */
            $thumbnailPath = $uploadedDocument->thumbnail_path;

            if ($thumbnailPath !== null && $thumbnailPath !== '') {
                $this->deleteOrFail($uploadedDocument->disk, $thumbnailPath);
            }
        }
    }

    /**
     * Delete one object, or make the purge fail.
     *
     * Deliberately not tolerant. Everywhere else a missing storage object is
     * reported and stepped over; here the caller is discharging a legal
     * obligation, so "probably gone" is not an answer it may return.
     */
    private function deleteOrFail(string $disk, string $path): void
    {
        try {
            $deleted = $this->filesystems->disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Failed to remove a stored file during legal retention purge.',
                previous: $exception,
            ));

            throw $exception;
        }

        if (! $deleted) {
            report(new RuntimeException(
                'Failed to remove a stored file during legal retention purge.',
            ));

            throw new RuntimeException(
                'Failed to remove a stored file during legal retention purge.',
            );
        }
    }
}
