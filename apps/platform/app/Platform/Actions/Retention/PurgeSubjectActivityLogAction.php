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

final class PurgeSubjectActivityLogAction
{
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

        foreach ($documentRequestIds as $documentRequestId) {
            $deletedCount += Activity::query()
                ->where('subject_type', (new DocumentRequest)->getMorphClass())
                ->where('subject_id', $documentRequestId)
                ->delete();
        }

        $uploadedDocumentsQuery = UploadedDocument::query();
        $uploadedDocumentsQuery->getQuery()->whereIn('document_request_id', $documentRequestIds);
        $uploadedDocumentIds = $uploadedDocumentsQuery->toBase()->pluck('id');

        foreach ($uploadedDocumentIds as $uploadedDocumentId) {
            $deletedCount += Activity::query()
                ->where('subject_type', (new UploadedDocument)->getMorphClass())
                ->where('subject_id', $uploadedDocumentId)
                ->delete();
        }

        $exportIds = DossierExport::query()
            ->where('dossier_id', $dossier->id)
            ->toBase()
            ->pluck('id');

        foreach ($exportIds as $exportId) {
            $deletedCount += Activity::query()
                ->where('subject_type', (new DossierExport)->getMorphClass())
                ->where('subject_id', $exportId)
                ->delete();
        }

        $audioRecordingIds = AudioRecording::query()
            ->where('dossier_id', $dossier->id)
            ->toBase()
            ->pluck('id');

        foreach ($audioRecordingIds as $audioRecordingId) {
            $deletedCount += Activity::query()
                ->where('subject_type', (new AudioRecording)->getMorphClass())
                ->where('subject_id', $audioRecordingId)
                ->delete();
        }

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

        foreach ($pipelineIds as $pipelineId) {
            $deletedCount += Activity::query()
                ->where('subject_type', (new Pipeline)->getMorphClass())
                ->where('subject_id', $pipelineId)
                ->delete();
        }

        return $deletedCount;
    }

    private function purgeForModel(Model $subject): int
    {
        return Activity::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->delete();
    }
}
