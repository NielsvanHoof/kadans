<?php

declare(strict_types=1);

use App\Audit\Models\Activity;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Pipelines\Support\PipelineState;

/*
|--------------------------------------------------------------------------
| Quiet pipeline writes
|--------------------------------------------------------------------------
|
| Pipeline and PipelineStep deliberately do not use LogsActivity, and every
| write in the transitions is saveQuietly() — steps and heartbeats change far
| more often than an audit trail should record (.ai/rules/pipelines.md). That
| is an invariant of the audit design, not an implementation detail: business
| meaningful pipeline events are logged explicitly by the calling action
| instead. Assert it directly so adding activity logging to the models, or
| routing a step write through a loud save, fails here.
|
*/

beforeEach(function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $this->tenant = $tenant;
    $this->pipelineState = resolve(PipelineState::class);
});

test('driving a run through every step transition writes no activity rows', function (): void {
    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => PipelineStatus::Pending,
    ]);

    foreach ([PipelineStepKey::Submit, PipelineStepKey::ProviderProcessing] as $position => $stepKey) {
        PipelineStep::factory()->create([
            'tenant_id' => $this->tenant->id,
            'pipeline_id' => $pipeline->id,
            'key' => $stepKey,
            'position' => $position,
            'status' => PipelineStepStatus::Pending,
        ]);
    }

    Activity::query()->delete();

    $this->pipelineState->start($pipeline, PipelineStepKey::Submit);
    $this->pipelineState->heartbeatStep($pipeline, PipelineStepKey::Submit);
    $this->pipelineState->complete($pipeline, PipelineStepKey::Submit);
    $this->pipelineState->heartbeat($pipeline);
    $this->pipelineState->start($pipeline, PipelineStepKey::ProviderProcessing);
    $this->pipelineState->skip($pipeline, PipelineStepKey::ProviderProcessing);

    expect(Activity::query()->count())->toBe(0);
});

test('failing a run writes no activity rows of its own', function (): void {
    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => PipelineStatus::Running,
    ]);
    PipelineStep::factory()->create([
        'tenant_id' => $this->tenant->id,
        'pipeline_id' => $pipeline->id,
        'key' => PipelineStepKey::Submit,
        'position' => 0,
        'status' => PipelineStepStatus::Running,
    ]);

    Activity::query()->delete();

    $this->pipelineState->fail(
        $pipeline,
        PipelineStepKey::Submit,
        errorCode: 'E_BOOM',
        errorMessage: 'Provider rejected the document.',
    );

    expect(Activity::query()->count())->toBe(0);
});
