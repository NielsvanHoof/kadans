<?php

declare(strict_types=1);

namespace App\AudioProcessing\Presenters;

use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Presenters\Lifecycle\DossierWorkspaceHref;
use App\Pipelines\Contracts\PipelineSubjectPresenter;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

final class AudioRecordingPipelineSubjectPresenter implements PipelineSubjectPresenter
{
    use DossierWorkspaceHref;

    public function type(): PipelineType
    {
        return PipelineType::AudioTranscription;
    }

    public function subjectClass(): string
    {
        return AudioRecording::class;
    }

    public function eagerLoads(): array
    {
        return ['dossier.client'];
    }

    public function label(Model $subject): ?string
    {
        return $subject instanceof AudioRecording ? $subject->original_filename : null;
    }

    public function context(Model $subject): ?string
    {
        return $subject instanceof AudioRecording ? $subject->dossier?->title : null;
    }

    public function dossierId(Model $subject): ?int
    {
        return $subject instanceof AudioRecording ? $subject->dossier_id : null;
    }

    public function href(Model $subject, Pipeline $pipeline): ?string
    {
        return $this->dossierHref($this->dossierId($subject));
    }
}
