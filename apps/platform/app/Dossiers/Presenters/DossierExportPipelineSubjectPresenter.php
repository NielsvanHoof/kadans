<?php

declare(strict_types=1);

namespace App\Dossiers\Presenters;

use App\Dossiers\Models\DossierExport;
use App\Pipelines\Contracts\PipelineSubjectPresenter;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

final class DossierExportPipelineSubjectPresenter implements PipelineSubjectPresenter
{
    use DossierWorkspaceHref;

    public function type(): PipelineType
    {
        return PipelineType::DossierExport;
    }

    public function subjectClass(): string
    {
        return DossierExport::class;
    }

    public function eagerLoads(): array
    {
        return ['dossier.client'];
    }

    public function label(Model $subject): ?string
    {
        return $subject instanceof DossierExport ? "Dossier export #{$subject->id}" : null;
    }

    public function context(Model $subject): ?string
    {
        return $subject instanceof DossierExport ? $subject->dossier?->title : null;
    }

    public function dossierId(Model $subject): ?int
    {
        return $subject instanceof DossierExport ? $subject->dossier_id : null;
    }

    public function href(Model $subject, Pipeline $pipeline): ?string
    {
        return $this->dossierHref($this->dossierId($subject));
    }
}
