<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Presenters\Ocr;

use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Presenters\Lifecycle\DossierWorkspaceHref;
use App\Pipelines\Contracts\PipelineSubjectPresenter;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

final class UploadedDocumentPipelineSubjectPresenter implements PipelineSubjectPresenter
{
    use DossierWorkspaceHref;

    public function type(): PipelineType
    {
        return PipelineType::OcrDocument;
    }

    public function subjectClass(): string
    {
        return UploadedDocument::class;
    }

    public function eagerLoads(): array
    {
        return ['documentRequest.dossier.client'];
    }

    public function label(Model $subject): ?string
    {
        return $subject instanceof UploadedDocument ? $subject->original_filename : null;
    }

    public function context(Model $subject): ?string
    {
        return $subject instanceof UploadedDocument ? $this->dossierContext($subject->documentRequest?->dossier) : null;
    }

    public function dossierId(Model $subject): ?int
    {
        return $subject instanceof UploadedDocument ? $subject->documentRequest?->dossier_id : null;
    }

    public function href(Model $subject, Pipeline $pipeline): ?string
    {
        return $this->dossierHref($this->dossierId($subject));
    }
}
