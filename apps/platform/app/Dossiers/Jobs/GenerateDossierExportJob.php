<?php

declare(strict_types=1);

namespace App\Dossiers\Jobs;

use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Actions\GenerateDossierExportAction;
use App\Dossiers\Actions\MarkDossierExportFailedAction;
use App\Dossiers\Models\DossierExport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

#[Backoff([10, 30, 60])]
#[Tries(3)]
final class GenerateDossierExportJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $pipelineId) {}

    public function uniqueId(): string
    {
        return (string) $this->pipelineId;
    }

    public function handle(GenerateDossierExportAction $generateDossierExport): void
    {
        $generateDossierExport->handle($this->pipelineId);
    }

    public function failed(Throwable $exception): void
    {
        $pipeline = Pipeline::query()->with('pipelineable')->find($this->pipelineId);
        $pipelineState = resolve(PipelineStateAction::class);

        if ($pipeline instanceof Pipeline && ! $pipeline->status->isTerminal()) {
            $pipelineState->handle(
                $pipeline,
                PipelineStepOperation::Fail,
                $pipeline->current_stage,
                $exception,
                errorCode: 'dossier_export_failed',
            );
        }

        $export = $pipeline?->pipelineable;

        if ($export instanceof DossierExport) {
            resolve(MarkDossierExportFailedAction::class)->handle($export, $exception);
        }
    }
}
