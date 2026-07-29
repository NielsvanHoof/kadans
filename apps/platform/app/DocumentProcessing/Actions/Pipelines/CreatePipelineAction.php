<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Actions\Pipelines;

use App\Accounts\Models\User;
use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Tenancy\Models\Tenant;
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

        throw_if(! $tenant instanceof Tenant || ! is_numeric($tenantId) || (int) $tenantId !== $tenant->id, RuntimeException::class, 'Cannot create a pipeline outside the current tenant.');

        $pipelineableId = $pipelineable->getKey();

        throw_unless(is_numeric($pipelineableId), RuntimeException::class, 'Pipeline subjects must have a numeric primary key.');

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
