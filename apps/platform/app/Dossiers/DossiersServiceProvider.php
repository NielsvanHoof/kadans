<?php

declare(strict_types=1);

namespace App\Dossiers;

use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Policies\AudioRecordingPolicy;
use App\Dossiers\Policies\DocumentRequestPolicy;
use App\Dossiers\Policies\DossierPolicy;
use App\Dossiers\Policies\UploadedDocumentPolicy;
use App\Dossiers\Retry\DossierExportPipelineRetryRunner;
use App\Pipelines\Support\PipelineRetryRunnerRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Dossier domain authorization.
 */
final class DossiersServiceProvider extends ServiceProvider
{
    public function boot(PipelineRetryRunnerRegistry $retryRunners): void
    {
        Gate::policy(DocumentRequest::class, DocumentRequestPolicy::class);
        Gate::policy(Dossier::class, DossierPolicy::class);
        Gate::policy(UploadedDocument::class, UploadedDocumentPolicy::class);
        Gate::policy(AudioRecording::class, AudioRecordingPolicy::class);

        $retryRunners->register($this->app->make(DossierExportPipelineRetryRunner::class));
    }
}
