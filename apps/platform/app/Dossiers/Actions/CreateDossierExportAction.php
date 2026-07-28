<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Actions\Pipelines\CreatePipelineAction;
use App\DocumentProcessing\Enums\PipelineType;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use Illuminate\Support\Facades\DB;

final class CreateDossierExportAction
{
    public function __construct(
        private readonly LogAuditActivityAction $logAuditActivity,
        private readonly CreatePipelineAction $createPipeline,
    ) {}

    public function handle(Dossier $dossier, User $requestedBy): DossierExport
    {
        return DB::transaction(function () use ($dossier, $requestedBy): DossierExport {
            $export = $dossier->exports()->create([
                'requested_by' => $requestedBy->id,
            ]);

            $this->createPipeline->handle(
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
