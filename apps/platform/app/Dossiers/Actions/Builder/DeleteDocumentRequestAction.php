<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\DossierLocks;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class DeleteDocumentRequestAction
{
    public function __construct(
        private FilesystemManager $filesystems,
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
    ) {}

    public function handle(DocumentRequest $documentRequest): void
    {
        /** @var list<array{disk: string, path: string}> $storedFiles */
        $storedFiles = DB::transaction(function () use ($documentRequest): array {
            $lockedDocumentRequest = $this->dossierLocks->documentRequest($documentRequest);
            $storedFiles = UploadedDocument::query()
                ->where('document_request_id', $lockedDocumentRequest->id)
                ->get()
                ->map(fn (UploadedDocument $uploadedDocument): array => [
                    'disk' => $uploadedDocument->disk,
                    'path' => $uploadedDocument->path,
                ])
                ->all();

            $this->logAuditActivity->handle(
                AuditEvent::DocumentRequestDeleted,
                $lockedDocumentRequest,
                [
                    'title' => $lockedDocumentRequest->title,
                    'type' => $lockedDocumentRequest->type->value,
                    'dossier_id' => $lockedDocumentRequest->dossier_id,
                ],
            );

            $lockedDocumentRequest->deleteOrFail();

            return $storedFiles;
        });

        foreach ($storedFiles as $storedFile) {
            $this->deleteStoredFile($storedFile);
        }
    }

    /**
     * @param  array{disk: string, path: string}  $storedFile
     */
    private function deleteStoredFile(array $storedFile): void
    {
        try {
            $deleted = $this->filesystems
                ->disk($storedFile['disk'])
                ->delete($storedFile['path']);
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Failed to remove the stored file for a deleted document request.',
                previous: $exception,
            ));

            return;
        }

        report_unless($deleted, new RuntimeException(
            'Failed to remove the stored file for a deleted document request.',
        ));
    }
}
