<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\DocumentProcessing\Actions\Pipelines\DeletePipelinesForSubjectsAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Remove one of several uploaded files from a multi-file document request.
 */
final readonly class DeleteUploadedDocumentAction
{
    public function __construct(
        private DeletePipelinesForSubjectsAction $deletePipelinesForSubjects,
    ) {}

    public function handle(UploadedDocument $uploadedDocument): void
    {
        $documentRequest = $uploadedDocument->documentRequest;

        Storage::disk($uploadedDocument->disk)->delete($uploadedDocument->path);
        $this->deletePipelinesForSubjects->handle([$uploadedDocument]);
        $uploadedDocument->delete();

        // Removing the last remaining file leaves nothing to review, so the
        // request reopens for submission rather than sitting "submitted"
        // with no attached file.
        if ($documentRequest->status === DocumentRequestStatus::Submitted
            && ! $documentRequest->uploadedDocuments()->toBase()->exists()) {
            $documentRequest->update([
                'status' => DocumentRequestStatus::Pending,
                'answered_at' => null,
            ]);
        }
    }
}
