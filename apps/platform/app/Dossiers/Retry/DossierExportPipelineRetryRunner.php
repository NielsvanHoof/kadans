<?php

declare(strict_types=1);

namespace App\Dossiers\Retry;

use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Transitions\DossierExportTransitions;
use App\Pipelines\Contracts\PipelineRetryRunner;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final readonly class DossierExportPipelineRetryRunner implements PipelineRetryRunner
{
    public function __construct(private DossierExportTransitions $transitions) {}

    public function type(): PipelineType
    {
        return PipelineType::DossierExport;
    }

    public function subjectClass(): string
    {
        return DossierExport::class;
    }

    public function resetSubject(Model $subject): void
    {
        throw_unless($subject instanceof DossierExport, RuntimeException::class, 'Dossier export retry runner received an unexpected subject.');

        $this->transitions->retry($subject);
    }

    public function dispatch(Pipeline $pipeline, Model $subject): void
    {
        dispatch(new GenerateDossierExportJob($pipeline->id))->afterCommit();
    }
}
