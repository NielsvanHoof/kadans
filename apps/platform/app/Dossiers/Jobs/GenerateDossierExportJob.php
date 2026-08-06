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
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

#[Backoff(self::BACKOFF_SECONDS)]
#[Tries(self::MAX_TRIES)]
#[Timeout(120)]
final class GenerateDossierExportJob implements ShouldBeUnique, ShouldQueue
{
    public $timeout;
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [10, 30, 60];

    public function __construct(public int $pipelineId) {}

    public function uniqueId(): string
    {
        return (string) $this->pipelineId;
    }

    /**
     * Auto-release the unique lock after worst-case attempts + backoff so a
     * SIGKILL / OOM cannot wedge the export forever
     */
    public function uniqueFor(): int
    {
        return ($this->timeout * self::MAX_TRIES) + array_sum(self::BACKOFF_SECONDS);
    }

    /**
     * Telescope / Horizon searchable tags (jobs carry IDs, not Eloquent models).
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'export',
            'pipeline:'.$this->pipelineId,
        ];
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
