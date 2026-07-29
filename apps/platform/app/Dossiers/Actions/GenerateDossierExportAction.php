<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Models\DossierExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

use function sprintf;

final readonly class GenerateDossierExportAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private PipelineStateAction $pipelineState,
    ) {}

    public function handle(int $pipelineId): void
    {
        $pipeline = Pipeline::query()->with([
            'pipelineable.dossier.client',
            'pipelineable.dossier.documentRequests.uploadedDocument',
            'pipelineable.dossier.documentRequests.reviewedBy',
        ])->findOrFail($pipelineId);
        $export = $pipeline->pipelineable;

        throw_unless($export instanceof DossierExport, RuntimeException::class, 'Dossier export pipeline subject is invalid.');

        if ($export->status === DossierExportStatus::Completed) {
            return;
        }

        $claimed = DB::transaction(function () use ($export): ?DossierExport {
            $query = DossierExport::query()->whereKey($export->id);
            $query->getQuery()->lockForUpdate();
            $locked = $query->firstOrFail();

            if ($locked->status === DossierExportStatus::Completed) {
                return null;
            }

            $locked->forceFill([
                'status' => DossierExportStatus::Processing,
                'error' => null,
                'failed_at' => null,
            ])->saveQuietly();

            return $locked;
        });

        if (! $claimed instanceof DossierExport) {
            return;
        }

        $archivePath = 'dossier-exports/'.$claimed->tenant_id.'/'.$claimed->dossier_id.'/'.$claimed->id.'.zip';
        $disk = (string) config('exports.disk');
        $temporaryPath = Storage::disk('local')->path(
            'dossier-export-tmp-'.bin2hex(random_bytes(16)).'.zip',
        );

        try {
            $this->pipelineState->handle($pipeline, PipelineStepOperation::Start, 'build_archive');
            $this->buildArchive($temporaryPath, $export);
            $this->pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'build_archive');
            $this->pipelineState->handle($pipeline, PipelineStepOperation::Start, 'store_archive');

            $stream = fopen($temporaryPath, 'rb');

            throw_if($stream === false, RuntimeException::class, 'Unable to store the dossier export archive.');

            $stored = Storage::disk($disk)->put($archivePath, $stream);
            fclose($stream);

            throw_if($stored === false, RuntimeException::class, 'Unable to store the dossier export archive.');

            $completedAt = now();
            $expiresAt = $completedAt->copy()->addMinutes((int) config('exports.expires_in_minutes'));

            $claimed->forceFill([
                'status' => DossierExportStatus::Completed,
                'disk' => $disk,
                'path' => $archivePath,
                'expires_at' => $expiresAt,
                'completed_at' => $completedAt,
            ])->saveQuietly();

            $this->logAuditActivity->handle(
                AuditEvent::DossierExportCompleted,
                $claimed,
                [
                    'dossier_id' => $claimed->dossier_id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                includeRequestContext: false,
            );

            $this->pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'store_archive');
        } catch (Throwable $exception) {
            if (Storage::disk($disk)->exists($archivePath)) {
                Storage::disk($disk)->delete($archivePath);
            }

            throw $exception;
        } finally {
            unlink($temporaryPath);
        }
    }

    private function buildArchive(string $temporaryPath, DossierExport $export): void
    {
        $dossier = $export->dossier;

        throw_if($dossier === null || $dossier->client === null, RuntimeException::class, 'Unable to export a dossier without its client.');

        $client = $dossier->client;
        $archive = new ZipArchive();

        throw_if($archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true, RuntimeException::class, 'Unable to open the dossier export archive.');

        $documents = [];
        $documentNumber = 0;

        foreach ($dossier->documentRequests as $documentRequest) {
            $uploadedDocument = $documentRequest->uploadedDocument;
            $document = [
                'id' => $documentRequest->id,
                'type' => $documentRequest->type->value,
                'title' => $documentRequest->title,
                'instructions' => $documentRequest->instructions,
                'status' => $documentRequest->status->value,
                'answer_text' => $documentRequest->answer_text,
                'answer_boolean' => $documentRequest->answer_boolean,
                'answered_at' => $documentRequest->answered_at?->toIso8601String(),
                'reviewed_at' => $documentRequest->reviewed_at?->toIso8601String(),
                'reviewed_by' => $documentRequest->reviewedBy?->name,
                'rejection_reason' => $documentRequest->rejection_reason,
            ];

            if ($uploadedDocument !== null) {
                $documentNumber++;
                $filename = $this->archiveFilename($documentNumber, $uploadedDocument->original_filename);
                $contents = Storage::disk($uploadedDocument->disk)->get($uploadedDocument->path);

                if ($contents === null) {
                    $archive->close();
                    throw new RuntimeException('Unable to read an uploaded document for export.');
                }

                if (! $archive->addFromString($filename, $contents)) {
                    $archive->close();
                    throw new RuntimeException('Unable to add an uploaded document to the export.');
                }

                $document['archive_path'] = $filename;
                $document['original_filename'] = $uploadedDocument->original_filename;
                $document['mime_type'] = $uploadedDocument->mime_type;
                $document['size_bytes'] = $uploadedDocument->size_bytes;
                $document['uploaded_at'] = $uploadedDocument->uploaded_at->toIso8601String();
                $document['processing_status'] = $uploadedDocument->processing_status->value;
            }

            $documents[] = $document;
        }

        $manifest = json_encode([
            'export_id' => $export->id,
            'generated_at' => now()->toIso8601String(),
            'dossier' => [
                'id' => $dossier->id,
                'title' => $dossier->title,
                'reference' => $dossier->reference,
                'status' => $dossier->status->value,
                'due_date' => $dossier->due_date?->toDateString(),
            ],
            'client' => [
                'name' => $client->name,
                'email' => $client->email,
            ],
            'documents' => $documents,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        throw_if(! $archive->addFromString('manifest.json', $manifest) || ! $archive->close(), RuntimeException::class, 'Unable to finalize the dossier export archive.');
    }

    private function archiveFilename(int $number, string $originalFilename): string
    {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $basename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME));
        $basename = $basename !== '' ? $basename : 'document';

        return sprintf(
            'documents/%03d-%s%s',
            $number,
            $basename,
            $extension !== '' ? '.'.mb_strtolower($extension) : '',
        );
    }
}
