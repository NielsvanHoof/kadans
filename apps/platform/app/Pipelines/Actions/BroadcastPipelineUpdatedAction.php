<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Events\PipelineUpdated;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Models\Tenant;

/**
 * Push a workspace pipeline.updated broadcast for live JSON / Inertia islands.
 */
final class BroadcastPipelineUpdatedAction
{
    public function handle(Pipeline $pipeline): void
    {
        $pipeline->loadMissing('tenant');

        $tenant = $pipeline->tenant;

        if (! $tenant instanceof Tenant) {
            return;
        }

        event(new PipelineUpdated($tenant->slug, $pipeline->id, $pipeline->type->value, $pipeline->status->value, $pipeline->pipelineable_type, $pipeline->pipelineable_id, $this->resolveDossierId($pipeline)));
    }

    private function resolveDossierId(Pipeline $pipeline): ?int
    {
        $pipeline->loadMissing('pipelineable');

        $subject = $pipeline->pipelineable;

        if ($subject instanceof DossierExport || $subject instanceof AudioRecording) {
            return $subject->dossier_id;
        }

        if ($subject instanceof UploadedDocument) {
            $dossierId = DocumentRequest::query()
                ->whereKey($subject->document_request_id)
                ->value('dossier_id');

            return is_numeric($dossierId) ? (int) $dossierId : null;
        }

        return null;
    }
}
