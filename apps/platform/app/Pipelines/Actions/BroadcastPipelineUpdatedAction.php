<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Pipelines\Contracts\PipelineSubjectPresenter;
use App\Pipelines\Events\PipelineUpdated;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\Workspace\TenantLookup;
use Illuminate\Database\Eloquent\Model;

/**
 * Push a workspace pipeline.updated broadcast for live JSON / Inertia islands.
 *
 * The dossier a run belongs to is per-subject knowledge, so it comes from the
 * owning domain's presenter rather than from Pipelines branching on model class.
 */
final readonly class BroadcastPipelineUpdatedAction
{
    public function __construct(
        private PipelineSubjectPresenterRegistry $subjectPresenters,
    ) {}

    public function handle(Pipeline $pipeline): void
    {
        // The cached directory rather than loadMissing('tenant'): only the slug
        // is needed, it does not change, and this runs once per pipeline
        // transition — hundreds of times during a bulk upload.
        $tenant = TenantLookup::findById($pipeline->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        event(new PipelineUpdated($tenant->slug, $pipeline->id, $pipeline->type->value, $pipeline->status->value, $pipeline->pipelineable_type, $pipeline->pipelineable_id, $this->resolveDossierId($pipeline)));
    }

    private function resolveDossierId(Pipeline $pipeline): ?int
    {
        $presenter = $this->subjectPresenters->for($pipeline->type);

        if (! $presenter instanceof PipelineSubjectPresenter) {
            return null;
        }

        $pipeline->loadMissing('pipelineable');
        $subject = $pipeline->pipelineable;

        // A purged subject still broadcasts; it just carries no dossier tag.
        return $subject instanceof Model ? $presenter->dossierId($subject) : null;
    }
}
