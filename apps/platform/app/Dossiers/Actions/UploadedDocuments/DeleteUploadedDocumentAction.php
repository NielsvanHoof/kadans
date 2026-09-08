<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Transitions\DocumentRequestTransitions;
use App\Pipelines\Actions\DeletePipelinesForSubjectsAction;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Remove one of several uploaded files from a multi-file document request.
 */
final readonly class DeleteUploadedDocumentAction
{
    public function __construct(
        private DeletePipelinesForSubjectsAction $deletePipelinesForSubjects,
        private DocumentRequestTransitions $documentRequestTransitions,
        private FilesystemManager $filesystems,
    ) {}

    public function handle(UploadedDocument $uploadedDocument): void
    {
        $documentRequest = $uploadedDocument->documentRequest;
        $disk = $uploadedDocument->disk;
        $path = $uploadedDocument->path;

        DB::transaction(function () use ($uploadedDocument, $documentRequest): void {
            $this->deletePipelinesForSubjects->handle([$uploadedDocument]);
            $uploadedDocument->delete();

            // Removing the last remaining file leaves nothing to review, so the
            // request reopens for submission rather than sitting "submitted"
            // with no attached file.
            if ($documentRequest->status === DocumentRequestStatus::Submitted
                && ! $documentRequest->uploadedDocuments()->toBase()->exists()) {
                $this->documentRequestTransitions->reopenForSubmission($documentRequest);
            }
        });

        // Storage delete is outside the transaction on purpose — same as
        // compensating deletes on the upload path after a successful commit.
        $this->deleteFileWithoutMaskingFailure(
            $disk,
            $path,
            'Failed to remove the storage object for a deleted uploaded document.',
        );
    }

    private function deleteFileWithoutMaskingFailure(
        string $disk,
        string $path,
        string $message,
    ): void {
        try {
            $deleted = $this->filesystems->disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report(new RuntimeException($message, previous: $exception));

            return;
        }

        report_unless($deleted, new RuntimeException($message));
    }
}
