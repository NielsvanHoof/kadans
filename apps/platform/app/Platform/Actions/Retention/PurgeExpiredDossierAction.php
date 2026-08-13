<?php

declare(strict_types=1);

namespace App\Platform\Actions\Retention;

use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\DeletePipelinesForSubjectsAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class PurgeExpiredDossierAction
{
    public function __construct(
        private DeleteStoredDocumentFilesAction $deleteStoredDocumentFiles,
        private DeleteStoredDossierExportFilesAction $deleteStoredDossierExportFiles,
        private DeleteStoredAudioFilesAction $deleteStoredAudioFiles,
        private DeletePipelinesForSubjectsAction $deletePipelinesForSubjects,
        private PurgeSubjectActivityLogAction $purgeSubjectActivityLog,
    ) {}

    /**
     * Permanently remove a soft-deleted dossier past legal retention.
     *
     * Database rows are removed in a transaction first. Storage blobs are
     * deleted only after commit so a rolled-back purge cannot destroy files
     * for a dossier that still exists.
     *
     * @return array{purged: bool, activity_rows_deleted: int, files_deleted: int}
     */
    public function handle(Dossier $dossier): array
    {
        if (! $dossier->trashed()) {
            return [
                'purged' => false,
                'activity_rows_deleted' => 0,
                'files_deleted' => 0,
            ];
        }

        $documentRequestIds = $dossier->documentRequests()->toBase()->pluck('id');

        $uploadedDocumentsQuery = UploadedDocument::query();
        $uploadedDocumentsQuery->getQuery()->whereIn('document_request_id', $documentRequestIds);
        $uploadedDocuments = $uploadedDocumentsQuery->get();
        $dossierExports = DossierExport::query()->where('dossier_id', $dossier->id)->get();
        $audioRecordings = $dossier->audioRecordings()->get();

        $filesDeleted = $uploadedDocuments->count()
            + $dossierExports->filter(
                static fn (DossierExport $export): bool => $export->path !== null,
            )->count()
            + $audioRecordings->count();

        try {
            $activityRowsDeleted = DB::transaction(function () use ($dossier, $uploadedDocuments, $dossierExports, $audioRecordings): int {
                $activityRowsDeleted = $this->purgeSubjectActivityLog->handle($dossier);
                $this->deletePipelinesForSubjects->handle([
                    ...$uploadedDocuments->all(),
                    ...$dossierExports->all(),
                    ...$audioRecordings->all(),
                ]);

                $dossier->disableLogging();
                $dossier->forceDelete();

                return $activityRowsDeleted;
            });

            // Blobs only after a successful commit — mirrors document delete ordering.
            $this->deleteStoredDocumentFiles->handle($uploadedDocuments);
            $this->deleteStoredDossierExportFiles->handle($dossierExports);
            $this->deleteStoredAudioFiles->handle($audioRecordings);
        } catch (Throwable $exception) {
            report($exception);
            Log::error('Legal retention dossier purge failed.', [
                'dossier_id' => $dossier->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return [
            'purged' => true,
            'activity_rows_deleted' => $activityRowsDeleted,
            'files_deleted' => $filesDeleted,
        ];
    }
}
