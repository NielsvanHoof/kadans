<?php

declare(strict_types=1);

namespace App\Platform\Actions\Retention;

use App\Audit\Models\Activity;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Models\Pipeline;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Delete the activity trail for a subject, and for a dossier's whole subtree.
 *
 * The subtree pass used to issue one DELETE per child id — a dossier with 125
 * children meant 125 statements — inside the transaction PurgeExpiredDossierAction
 * opens, so activity_log lock duration scaled with child count on a path that
 * runs up to 50 times per tenant every night. The ids are collected the same
 * way; only the deletes are batched, using the (subject_type, subject_id)
 * composite index that nullableMorphs() already created.
 */
final class PurgeSubjectActivityLogAction
{
    /**
     * Ids per DELETE. Bounded so a dossier with thousands of children cannot
     * build one enormous IN list, and so the statement stays under SQLite's
     * bound-parameter limit in tests.
     */
    private const int DELETE_CHUNK_SIZE = 500;

    public function handle(Model $subject): int
    {
        if ($subject instanceof Dossier) {
            return $this->purgeDossierActivity($subject);
        }

        return $this->purgeForModel($subject);
    }

    private function purgeDossierActivity(Dossier $dossier): int
    {
        $deletedCount = $this->purgeForModel($dossier);

        $documentRequestIds = DocumentRequest::query()
            ->where('dossier_id', $dossier->id)
            ->pluck('id');

        $deletedCount += $this->purgeForSubjectIds((new DocumentRequest)->getMorphClass(), $documentRequestIds);

        $uploadedDocumentsQuery = UploadedDocument::query();
        $uploadedDocumentsQuery->getQuery()->whereIn('document_request_id', $documentRequestIds);
        $uploadedDocumentIds = $uploadedDocumentsQuery->toBase()->pluck('id');

        $deletedCount += $this->purgeForSubjectIds((new UploadedDocument)->getMorphClass(), $uploadedDocumentIds);

        $exportIds = DossierExport::query()
            ->where('dossier_id', $dossier->id)
            ->toBase()
            ->pluck('id');

        $deletedCount += $this->purgeForSubjectIds((new DossierExport)->getMorphClass(), $exportIds);

        $audioRecordingIds = AudioRecording::query()
            ->where('dossier_id', $dossier->id)
            ->toBase()
            ->pluck('id');

        $deletedCount += $this->purgeForSubjectIds((new AudioRecording)->getMorphClass(), $audioRecordingIds);

        $pipelineQuery = Pipeline::query();
        $pipelineIds = $pipelineQuery->getQuery()
            ->where(function (Builder $query) use ($dossier, $uploadedDocumentIds, $exportIds, $audioRecordingIds): void {
                $query
                    ->where(function (Builder $query) use ($dossier): void {
                        $query->where('pipelineable_type', (new Dossier)->getMorphClass())
                            ->where('pipelineable_id', $dossier->id);
                    })
                    ->orWhere(function (Builder $query) use ($uploadedDocumentIds, $exportIds, $audioRecordingIds): void {
                        $query
                            ->where(function (Builder $query) use ($uploadedDocumentIds): void {
                                $query->where('pipelineable_type', (new UploadedDocument)->getMorphClass())
                                    ->whereIn('pipelineable_id', $uploadedDocumentIds);
                            })
                            ->orWhere(function (Builder $query) use ($exportIds): void {
                                $query->where('pipelineable_type', (new DossierExport)->getMorphClass())
                                    ->whereIn('pipelineable_id', $exportIds);
                            })
                            ->orWhere(function (Builder $query) use ($audioRecordingIds): void {
                                $query->where('pipelineable_type', (new AudioRecording)->getMorphClass())
                                    ->whereIn('pipelineable_id', $audioRecordingIds);
                            });
                    });
            })
            ->pluck('id');

        return $deletedCount + $this->purgeForSubjectIds((new Pipeline)->getMorphClass(), $pipelineIds);
    }

    private function purgeForModel(Model $subject): int
    {
        return Activity::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->delete();
    }

    /**
     * Delete every activity row for one subject type and a set of ids.
     *
     * Built on Activity::query() and terminated on toBase() so the model's
     * scopes still apply to the delete, matching the idiom used elsewhere for
     * whereIn on an Eloquent builder.
     *
     * @param  Collection<int, mixed>  $subjectIds
     */
    private function purgeForSubjectIds(string $morphClass, Collection $subjectIds): int
    {
        $deletedCount = 0;

        foreach ($subjectIds->chunk(self::DELETE_CHUNK_SIZE) as $chunk) {
            $ids = $chunk->values()->all();

            if ($ids === []) {
                continue;
            }

            $activityQuery = Activity::query()->where('subject_type', $morphClass);
            $activityQuery->getQuery()->whereIn('subject_id', $ids);

            $deletedCount += $activityQuery->toBase()->delete();
        }

        return $deletedCount;
    }
}
