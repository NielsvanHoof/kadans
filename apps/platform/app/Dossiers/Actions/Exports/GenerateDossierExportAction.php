<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Exports;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Transitions\Exports\DossierExportTransitions;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use Illuminate\Support\Facades\Config;
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
        private PipelineState $pipelineState,
        private DossierExportTransitions $transitions,
    ) {}

    public function handle(int $pipelineId): void
    {
        $pipeline = Pipeline::query()->with([
            'pipelineable.dossier.client',
            'pipelineable.dossier.documentRequests.uploadedDocuments',
            'pipelineable.dossier.documentRequests.reviewedBy',
        ])->findOrFail($pipelineId);
        $export = $pipeline->pipelineable;

        throw_unless($export instanceof DossierExport, RuntimeException::class, 'Dossier export pipeline subject is invalid.');

        if (! $this->transitions->canStart($export)) {
            return;
        }

        $claimed = DB::transaction(function () use ($export): ?DossierExport {
            $query = DossierExport::query()->whereKey($export->id);
            $query->getQuery()->lockForUpdate();
            $locked = $query->firstOrFail();

            if (! $this->transitions->canStart($locked)) {
                return null;
            }

            $this->transitions->start($locked);

            return $locked;
        });

        if (! $claimed instanceof DossierExport) {
            return;
        }

        $archivePath = 'dossier-exports/'.$claimed->tenant_id.'/'.$claimed->dossier_id.'/'.$claimed->id.'.zip';
        $disk = Config::string('exports.disk');
        $temporaryPath = Storage::disk('local')->path(
            'dossier-export-tmp-'.bin2hex(random_bytes(16)).'.zip',
        );

        // Source files are staged here so ZipArchive can read them lazily at
        // close(). One directory means one cleanup, wherever the build threw.
        $stagingDirectory = 'dossier-export-staging-'.bin2hex(random_bytes(16));

        try {
            $this->pipelineState->start($pipeline, PipelineStepKey::BuildArchive);
            $this->buildArchive($temporaryPath, $export, $stagingDirectory);
            $this->pipelineState->complete($pipeline, PipelineStepKey::BuildArchive);
            $this->pipelineState->start($pipeline, PipelineStepKey::StoreArchive);

            $stream = fopen($temporaryPath, 'rb');

            throw_if($stream === false, RuntimeException::class, 'Unable to store the dossier export archive.');

            $stored = Storage::disk($disk)->put($archivePath, $stream);
            fclose($stream);

            throw_if($stored === false, RuntimeException::class, 'Unable to store the dossier export archive.');

            $completedAt = now();
            $expiresAt = $completedAt->copy()->addMinutes(Config::integer('exports.expires_in_minutes'));

            $this->transitions->complete($claimed, $disk, $archivePath, $completedAt, $expiresAt);

            $this->logAuditActivity->handle(
                AuditEvent::DossierExportCompleted,
                $claimed,
                [
                    'dossier_id' => $claimed->dossier_id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                includeRequestContext: false,
            );

            $this->pipelineState->complete($pipeline, PipelineStepKey::StoreArchive);
        } catch (Throwable $exception) {
            if (Storage::disk($disk)->exists($archivePath)) {
                Storage::disk($disk)->delete($archivePath);
            }

            throw $exception;
        } finally {
            Storage::disk('local')->deleteDirectory($stagingDirectory);

            // Guarded: buildArchive() can throw before ZipArchive::open()
            // creates the file — a dossier without a client does — and a bare
            // unlink() then warns on top of the real exception.
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function buildArchive(string $temporaryPath, DossierExport $export, string $stagingDirectory): void
    {
        $dossier = $export->dossier;

        throw_if($dossier === null || $dossier->client === null, RuntimeException::class, 'Unable to export a dossier without its client.');

        $client = $dossier->client;
        $archive = new ZipArchive();

        throw_if($archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true, RuntimeException::class, 'Unable to open the dossier export archive.');

        $documents = [];
        $documentNumber = 0;

        foreach ($dossier->documentRequests as $documentRequest) {
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
                'files' => [],
            ];

            foreach ($documentRequest->uploadedDocuments as $uploadedDocument) {
                $documentNumber++;
                $filename = $this->archiveFilename($documentNumber, $uploadedDocument->original_filename);

                try {
                    $stagedPath = $this->stageForArchive(
                        $uploadedDocument->disk,
                        $uploadedDocument->path,
                        $stagingDirectory.'/'.$documentNumber,
                    );
                } catch (Throwable $exception) {
                    $archive->close();

                    throw $exception;
                }

                if (! $archive->addFile($stagedPath, $filename)) {
                    $archive->close();
                    throw new RuntimeException('Unable to add an uploaded document to the export.');
                }

                $document['files'][] = [
                    'archive_path' => $filename,
                    'original_filename' => $uploadedDocument->original_filename,
                    'mime_type' => $uploadedDocument->mime_type,
                    'size_bytes' => $uploadedDocument->size_bytes,
                    'uploaded_at' => $uploadedDocument->uploaded_at->toIso8601String(),
                    'processing_status' => $uploadedDocument->processing_status->value,
                ];
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

    /**
     * Copy a stored object to local disk and return its real path.
     *
     * The archive used to be built with addFromString(), which makes
     * ZipArchive hold every file's bytes until close() — 400 MB of scans meant
     * ~400 MB of RSS on a queue worker with no memory hint. addFile() reads
     * lazily at close() instead, and the copy itself streams, so peak memory
     * is one stream buffer rather than one whole document.
     */
    private function stageForArchive(string $sourceDisk, string $sourcePath, string $stagedPath): string
    {
        $source = Storage::disk($sourceDisk)->readStream($sourcePath);

        throw_if($source === null, RuntimeException::class, 'Unable to read an uploaded document for export.');

        try {
            $staged = Storage::disk('local')->writeStream($stagedPath, $source);
        } finally {
            fclose($source);
        }

        throw_if(! $staged, RuntimeException::class, 'Unable to stage an uploaded document for export.');

        return Storage::disk('local')->path($stagedPath);
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
