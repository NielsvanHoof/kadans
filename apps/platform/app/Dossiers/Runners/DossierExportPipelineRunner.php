<?php

declare(strict_types=1);

namespace App\Dossiers\Runners;

use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Transitions\DossierExportTransitions;
use App\Pipelines\Contracts\PipelineRunner;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final readonly class DossierExportPipelineRunner implements PipelineRunner
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
        throw_unless($subject instanceof DossierExport, RuntimeException::class, 'The dossier export pipeline runner received an unexpected subject.');

        $this->transitions->retry($subject);
    }

    /**
     * The export job carries only the pipeline id; it resolves the export from
     * the run itself.
     */
    public function dispatch(Pipeline $pipeline, Model $subject): void
    {
        dispatch(new GenerateDossierExportJob($pipeline->id))->afterCommit();
    }
}
