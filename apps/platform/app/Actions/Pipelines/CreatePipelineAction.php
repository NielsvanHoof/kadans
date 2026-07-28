<?php

declare(strict_types=1);

namespace App\Actions\Pipelines;

use App\Enums\PipelineStatus;
use App\Enums\PipelineType;
use App\Models\Pipeline;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class CreatePipelineAction
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Model $pipelineable,
        PipelineType $type,
        ?User $initiatedBy = null,
        array $metadata = [],
    ): Pipeline {
        $tenant = Tenant::current();
        $tenantId = $pipelineable->getAttribute('tenant_id');

        if (! $tenant instanceof Tenant || ! is_numeric($tenantId) || (int) $tenantId !== $tenant->id) {
            throw new RuntimeException('Cannot create a pipeline outside the current tenant.');
        }

        $pipelineableId = $pipelineable->getKey();

        if (! is_numeric($pipelineableId)) {
            throw new RuntimeException('Pipeline subjects must have a numeric primary key.');
        }

        $pipeline = Pipeline::query()->create([
            'tenant_id' => $tenant->id,
            'type' => $type,
            'status' => PipelineStatus::Pending,
            'pipelineable_type' => $pipelineable->getMorphClass(),
            'pipelineable_id' => (int) $pipelineableId,
            'initiated_by' => $initiatedBy?->id,
            'metadata' => $metadata,
        ]);

        foreach ($type->stepKeys() as $position => $key) {
            $pipeline->steps()->create([
                'tenant_id' => $tenant->id,
                'key' => $key,
                'position' => $position,
            ]);
        }

        return $pipeline->load('steps');
    }
}
