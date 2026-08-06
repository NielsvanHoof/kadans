<?php

declare(strict_types=1);

namespace App\Reporting\Queries;

use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Data\PipelineShowData;
use App\Pipelines\Data\PipelineSubjectData;
use App\Pipelines\Models\Pipeline;
use App\Shared\Database\MorphType;
use App\Tenancy\Models\Tenant;

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

    private function subjectData(Pipeline $pipeline): PipelineSubjectData
    {
        $subject = $pipeline->pipelineable;
        $type = MorphType::classBasename($pipeline->pipelineable_type);
        $label = null;
        $context = null;
        $href = null;

        if ($subject instanceof DossierExport) {
            $label = "Dossier export #{$subject->id}";
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
