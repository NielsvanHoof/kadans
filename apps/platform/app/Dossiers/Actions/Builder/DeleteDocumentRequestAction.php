<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class DeleteDocumentRequestAction
{
    public function __construct(
        private FilesystemManager $filesystems,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(DocumentRequest $documentRequest): void
    {
        /** @var array{disk: string, path: string}|null $storedFile */
        $storedFile = DB::transaction(function () use ($documentRequest): ?array {
            $documentRequestQuery = DocumentRequest::query()
                ->whereKey($documentRequest->getKey());
            $documentRequestQuery->getQuery()->lockForUpdate();

            $lockedDocumentRequest = $documentRequestQuery->firstOrFail();
            $uploadedDocument = UploadedDocument::query()
                ->where('document_request_id', $lockedDocumentRequest->id)
                ->first();
            $storedFile = $uploadedDocument instanceof UploadedDocument
                ? [
                    'disk' => $uploadedDocument->disk,
                    'path' => $uploadedDocument->path,
                ]
                : null;

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

            return $storedFile;
        });

        if ($storedFile === null) {
            return;
        }

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
