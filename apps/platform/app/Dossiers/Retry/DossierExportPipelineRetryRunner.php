<?php

declare(strict_types=1);

namespace App\Dossiers\Retry;

use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Dossiers\Models\DossierExport;
use App\Pipelines\Contracts\PipelineRetryRunner;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

final class DossierExportPipelineRetryRunner implements PipelineRetryRunner
{
    public function type(): PipelineType
    {
        return PipelineType::DossierExport;
    }

    public function subjectClass(): string
    {
        return DossierExport::class;
    }

    /**
     * `disk` and `path` are left alone: GenerateDossierExportAction overwrites
     * them on success, and `isDownloadable()` already fails while the export
     * is not completed.
     */
    public function resetSubject(Model $subject): void
    {
        $subject->forceFill([
            'status' => DossierExportStatus::Pending,
            'error' => null,
            'expires_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ])->saveQuietly();
    }

    public function dispatch(Pipeline $pipeline, Model $subject): void
    {
        dispatch(new GenerateDossierExportJob($pipeline->id))->afterCommit();
    }
}
