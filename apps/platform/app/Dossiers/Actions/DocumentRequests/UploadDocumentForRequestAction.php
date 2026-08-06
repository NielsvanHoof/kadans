<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Transitions\DocumentRequestTransitions;
use App\Pipelines\Actions\CreatePipelineAction;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Closure;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function is_int;
use function is_string;
use function sprintf;

final readonly class UploadDocumentForRequestAction
{
    public function __construct(
        private FilesystemManager $filesystems,
        private DocumentRequestTransitions $documentRequestTransitions,
        private CreatePipelineAction $createPipeline,
    ) {}

    /**
     * Store a file for a document request and mark the request as submitted.
     * Replaces any previous upload for the same request, unless the request's
     * `allow_multiple` configuration is enabled, in which case the file is
     * appended alongside any existing uploads instead.
     *
     * @param  (Closure(UploadedDocument, DocumentRequest): void)|null  $afterPersist
     */
    public function handle(
        DocumentRequest $documentRequest,
        UploadedFile $file,
        ?Closure $afterPersist = null,
        SubmissionContext $context = SubmissionContext::Staff,
    ): UploadedDocument {
        throw_if($documentRequest->type !== QuestionnaireItemType::File, InvalidArgumentException::class, 'Only file items accept uploads.');

        $disk = (string) config('filesystems.default', 'local');
        $directory = sprintf(
            'tenants/%d/dossiers/%d',
            $documentRequest->tenant_id,
            $documentRequest->dossier_id,
        );

        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $path = $this->filesystems
            ->disk($disk)
            ->putFileAs($directory, $file, $filename);

        throw_if(! is_string($path) || $path === '', RuntimeException::class, 'Failed to store the uploaded document.');

        $sizeBytes = $file->getSize();

        /** @var array{disk: string, path: string}|null $replacedFile */
        $replacedFile = null;
        $pipeline = null;

        try {
            $uploadedDocument = DB::transaction(function () use (
                $documentRequest,
                $disk,
                $path,
                $file,
                $sizeBytes,
                $afterPersist,
                $context,
                &$pipeline,
                &$replacedFile,
            ): UploadedDocument {
                $documentRequestQuery = DocumentRequest::query()
                    ->whereKey($documentRequest->getKey());
                $documentRequestQuery->getQuery()->lockForUpdate();

                $lockedDocumentRequest = $documentRequestQuery->firstOrFail();
                $allowMultiple = ($lockedDocumentRequest->configuration['allow_multiple'] ?? false) === true;
                $existing = $allowMultiple
                    ? null
                    : UploadedDocument::query()
                        ->where('document_request_id', $lockedDocumentRequest->id)
                        ->first();

                $attributes = [
                    'disk' => $disk,
                    'path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_bytes' => is_int($sizeBytes) ? $sizeBytes : 0,
                    'uploaded_at' => now(),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                    'processing_status' => DocumentProcessingStatus::Pending,
                    'extracted_text' => null,
                    'textract_job_id' => null,
                    'processing_error' => null,
                    'processing_started_at' => null,
                    'processing_finished_at' => null,
                ];

                if ($existing instanceof UploadedDocument) {
                    $replacedFile = [
                        'disk' => $existing->disk,
                        'path' => $existing->path,
                    ];

                    $existing->update($attributes);
                    $uploadedDocument = $existing;
                } else {
                    $uploadedDocument = $lockedDocumentRequest
                        ->uploadedDocuments()
                        ->create($attributes);
                }

                $this->documentRequestTransitions->submitUpload($lockedDocumentRequest, $context);

                if ($afterPersist instanceof Closure) {
                    $afterPersist($uploadedDocument, $lockedDocumentRequest);
                }

                $pipeline = $this->createPipeline->handle(
                    $uploadedDocument,
                    PipelineType::OcrDocument,
                    metadata: [
                        'source' => $context->value,
                        'original_filename' => $uploadedDocument->original_filename,
                    ],
                );

                return $uploadedDocument;
            });
        } catch (Throwable $exception) {
            $this->deleteFileWithoutMaskingFailure(
                $disk,
                $path,
                'Failed to remove a newly uploaded document after its database transaction rolled back.',
            );

            throw $exception;
        }

        if ($replacedFile !== null && $replacedFile['path'] !== $path) {
            $this->deleteFileWithoutMaskingFailure(
                $replacedFile['disk'],
                $replacedFile['path'],
                'Failed to remove the file replaced by a newer document upload.',
            );
        }

        throw_if(! $pipeline instanceof Pipeline, RuntimeException::class, 'Failed to create the document processing pipeline.');

        dispatch(new ProcessUploadedDocumentJob($uploadedDocument->id, $uploadedDocument->path, $pipeline->id));

        return $uploadedDocument;
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
