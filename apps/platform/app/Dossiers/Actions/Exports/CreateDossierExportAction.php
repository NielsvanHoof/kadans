<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Exports;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Pipelines\Actions\StartPipelineAction;
use App\Pipelines\Enums\PipelineType;
use Illuminate\Support\Facades\DB;

final readonly class CreateDossierExportAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private StartPipelineAction $startPipeline,
    ) {}

    /**
     * Queues the archive job itself, so callers only ask for an export rather
     * than remembering which job backs it.
     */
    public function handle(Dossier $dossier, User $requestedBy): DossierExport
    {
        return DB::transaction(function () use ($dossier, $requestedBy): DossierExport {
            $export = $dossier->exports()->create([
                'requested_by' => $requestedBy->id,
            ]);

            $this->startPipeline->handle(
                $export,
                PipelineType::DossierExport,
                $requestedBy,
                ['dossier_id' => $dossier->id],
            );

            $this->logAuditActivity->handle(
                AuditEvent::DossierExportRequested,
                $export,
                [
                    'dossier_id' => $dossier->id,
                    'title' => $dossier->title,
                ],
                $requestedBy,
            );

            return $export->load('pipeline');
        });
    }
}
