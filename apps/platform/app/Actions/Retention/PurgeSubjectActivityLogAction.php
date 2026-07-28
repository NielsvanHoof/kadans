<?php

declare(strict_types=1);

namespace App\Actions\Retention;

use App\Models\Activity;
use App\Models\DocumentRequest;
use App\Models\Dossier;
use App\Models\DossierExport;
use App\Models\Pipeline;
use App\Models\UploadedDocument;
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
                ->where('subject_type', DocumentRequest::class)
                ->where('subject_id', $documentRequestId)
                ->delete();
        }

        $uploadedDocumentsQuery = UploadedDocument::query();
        $uploadedDocumentsQuery->getQuery()->whereIn('document_request_id', $documentRequestIds);
        $uploadedDocumentIds = $uploadedDocumentsQuery->toBase()->pluck('id');

        foreach ($uploadedDocumentIds as $uploadedDocumentId) {
            $deletedCount += Activity::query()
                ->where('subject_type', UploadedDocument::class)
                ->where('subject_id', $uploadedDocumentId)
                ->delete();
        }

        $exportIds = DossierExport::query()
            ->where('dossier_id', $dossier->id)
            ->toBase()
            ->pluck('id');

        foreach ($exportIds as $exportId) {
            $deletedCount += Activity::query()
                ->where('subject_type', DossierExport::class)
                ->where('subject_id', $exportId)
                ->delete();
        }

        $pipelineQuery = Pipeline::query();
        $pipelineIds = $pipelineQuery->getQuery()
            ->where(function ($query) use ($dossier, $uploadedDocumentIds, $exportIds): void {
                $query
                    ->where(function ($query) use ($dossier): void {
                        $query->where('pipelineable_type', Dossier::class)
                            ->where('pipelineable_id', $dossier->id);
                    })
                    ->orWhere(function ($query) use ($uploadedDocumentIds, $exportIds): void {
                        $query
                            ->where(function ($query) use ($uploadedDocumentIds): void {
                                $query->where('pipelineable_type', UploadedDocument::class)
                                    ->whereIn('pipelineable_id', $uploadedDocumentIds);
                            })
                            ->orWhere(function ($query) use ($exportIds): void {
                                $query->where('pipelineable_type', DossierExport::class)
                                    ->whereIn('pipelineable_id', $exportIds);
                            });
                    });
            })
            ->pluck('id');

        foreach ($pipelineIds as $pipelineId) {
            $deletedCount += Activity::query()
                ->where('subject_type', Pipeline::class)
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
