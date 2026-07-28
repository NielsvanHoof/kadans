<?php

declare(strict_types=1);

namespace App\Queries\Reporting;

use App\Data\Pipelines\PipelineShowData;
use App\Data\Pipelines\PipelineSubjectData;
use App\Models\DossierExport;
use App\Models\Pipeline;
use App\Models\UploadedDocument;
use App\Models\Tenant;

final class FetchPipelineShowQuery
{
    /**
     * @return array{pipeline: array<string, mixed>}
     */
    public function handle(Pipeline $pipeline): array
    {
        $pipeline->load(['steps', 'pipelineable']);
        $pipeline->loadMorph('pipelineable', [
            DossierExport::class => ['dossier.client'],
            UploadedDocument::class => ['documentRequest.dossier.client'],
        ]);

        return [
            'pipeline' => PipelineShowData::fromModel(
                $pipeline,
                $this->subjectData($pipeline),
            )->toArray(),
        ];
    }

    private function subjectData(Pipeline $pipeline): ?PipelineSubjectData
    {
        if ($pipeline->pipelineable_id === null) {
            return null;
        }

        $subject = $pipeline->pipelineable;
        $type = class_basename($pipeline->pipelineable_type ?? 'Unknown');
        $label = null;
        $context = null;
        $href = null;

        if ($subject instanceof DossierExport) {
            $label = 'Dossier export #'.$subject->id;
            $context = $subject->dossier?->title;
            $href = $this->dossierHref($subject->dossier?->id);
        } elseif ($subject instanceof UploadedDocument) {
            $label = $subject->original_filename;
            $context = $subject->documentRequest?->dossier?->title;
            $href = $this->dossierHref($subject->documentRequest?->dossier?->id);
        }

        return new PipelineSubjectData(
            type: $type,
            id: $pipeline->pipelineable_id,
            label: $label,
            context: $context,
            href: $href,
        );
    }

    private function dossierHref(?int $dossierId): ?string
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant || $dossierId === null) {
            return null;
        }

        return route('workspaces.dossiers.show', [
            'tenant' => $tenant->slug,
            'dossier' => $dossierId,
        ]);
    }
}
