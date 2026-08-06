<?php

declare(strict_types=1);

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Pipelines\Transitions\PipelineTransitions;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| PipelineTransitions
|--------------------------------------------------------------------------
|
| Feature tests drive OCR and export pipelines along their happy paths, which
| leaves the guard and idempotence branches untested — exactly the ones that
| matter when a queued job is retried or a broadcast is delivered twice.
|
*/

beforeEach(function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $this->tenant = $tenant;
    $this->transitions = resolve(PipelineTransitions::class);
});

function pipelineWith(PipelineStatus $status = PipelineStatus::Pending): Pipeline
{
    return Pipeline::factory()->create([
        'tenant_id' => test()->tenant->id,
        'status' => $status,
    ]);
}

function stepFor(
    Pipeline $pipeline,
    PipelineStepStatus $status = PipelineStepStatus::Pending,
    string $key = 'process',
    int $position = 0,
): PipelineStep {
    return PipelineStep::factory()->create([
        'tenant_id' => test()->tenant->id,
        'pipeline_id' => $pipeline->id,
        'key' => $key,
        'position' => $position,
        'status' => $status,
    ]);
}

describe('start', function (): void {
    it('marks the step and pipeline running and counts the attempt', function (): void {
        $pipeline = pipelineWith();
        $step = stepFor($pipeline);

        $this->transitions->start($pipeline, $step);

        expect($step->fresh())
            ->status->toBe(PipelineStepStatus::Running)
            ->attempts->toBe(1)
            ->and($pipeline->fresh())
            ->status->toBe(PipelineStatus::Running)
            ->current_stage->toBe('process');
    });

    it('clears a previous error when retried', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Failed);
        $step = stepFor($pipeline, PipelineStepStatus::Failed);
        $step->forceFill(['error_code' => 'E_BOOM', 'error_message' => 'boom'])->saveQuietly();

        $this->transitions->start($pipeline, $step);

        expect($step->fresh())
            ->error_code->toBeNull()
            ->error_message->toBeNull()
            ->failed_at->toBeNull()
            ->attempts->toBe(1);
    });

    it('is a no-op for a step that already finished', function (PipelineStepStatus $status): void {
        $pipeline = pipelineWith();
        $step = stepFor($pipeline, $status);

        $this->transitions->start($pipeline, $step);

        expect($step->fresh()->status)->toBe($status)
            ->and($step->fresh()->attempts)->toBe(0);
    })->with([
        'completed' => [PipelineStepStatus::Completed],
        'skipped' => [PipelineStepStatus::Skipped],
    ]);

    it('does not double-count attempts for an already running step', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Running);
        $step = stepFor($pipeline);

        $this->transitions->start($pipeline, $step);
        $this->transitions->start($pipeline, $step->fresh());

        expect($step->fresh()->attempts)->toBe(1);
    });

    it('refuses to run a step on a closed pipeline', function (PipelineStatus $status): void {
        $pipeline = pipelineWith($status);
        $step = stepFor($pipeline);

        expect(fn () => $this->transitions->start($pipeline, $step))
            ->toThrow(ValidationException::class);
    })->with([
        'completed' => [PipelineStatus::Completed],
        'cancelled' => [PipelineStatus::Cancelled],
    ]);

    it('preserves the original started_at across retries', function (): void {
        $pipeline = pipelineWith();
        $first = stepFor($pipeline, key: 'first', position: 0);

        $this->transitions->start($pipeline, $first);
        $startedAt = $pipeline->fresh()->started_at;

        $this->travel(1)->minutes();
        $second = stepFor($pipeline, key: 'second', position: 1);
        $this->transitions->start($pipeline->fresh(), $second);

        expect($pipeline->fresh()->started_at->timestamp)->toBe($startedAt->timestamp);
    });
});

describe('complete', function (): void {
    it('advances to the next pending step', function (): void {
        $pipeline = pipelineWith();
        $first = stepFor($pipeline, key: 'first', position: 0);
        stepFor($pipeline, key: 'second', position: 1);

        $this->transitions->start($pipeline, $first);
        $this->transitions->complete($pipeline->fresh(), $first->fresh());

        expect($pipeline->fresh())
            ->status->toBe(PipelineStatus::Running)
            ->current_stage->toBe('second')
            ->completed_at->toBeNull();
    });

    it('completes the pipeline when no pending step remains', function (): void {
        $pipeline = pipelineWith();
        $only = stepFor($pipeline);

        $this->transitions->start($pipeline, $only);
        $this->transitions->complete($pipeline->fresh(), $only->fresh());

        expect($pipeline->fresh())
            ->status->toBe(PipelineStatus::Completed)
            ->current_stage->toBeNull()
            ->completed_at->not->toBeNull();
    });

    it('picks the next step by position, not by id', function (): void {
        $pipeline = pipelineWith();
        // Created out of order on purpose.
        $later = stepFor($pipeline, key: 'later', position: 5);
        $sooner = stepFor($pipeline, key: 'sooner', position: 1);
        $first = stepFor($pipeline, key: 'first', position: 0);

        $this->transitions->start($pipeline, $first);
        $this->transitions->complete($pipeline->fresh(), $first->fresh());

        expect($pipeline->fresh()->current_stage)->toBe($sooner->key)
            ->and($later->fresh()->status)->toBe(PipelineStepStatus::Pending);
    });

    it('is idempotent for an already completed step', function (): void {
        $pipeline = pipelineWith();
        $step = stepFor($pipeline, PipelineStepStatus::Completed);

        $this->transitions->complete($pipeline, $step);

        expect($step->fresh()->status)->toBe(PipelineStepStatus::Completed);
    });

    it('refuses to complete a step that never started', function (): void {
        $pipeline = pipelineWith();
        $step = stepFor($pipeline);

        expect(fn () => $this->transitions->complete($pipeline, $step))
            ->toThrow(ValidationException::class);
    });
});

describe('skip', function (): void {
    it('skips a pending step and advances', function (): void {
        $pipeline = pipelineWith();
        $skipped = stepFor($pipeline, key: 'skipped', position: 0);
        stepFor($pipeline, key: 'next', position: 1);

        $this->transitions->skip($pipeline, $skipped);

        expect($skipped->fresh())
            ->status->toBe(PipelineStepStatus::Skipped)
            ->completed_at->not->toBeNull()
            ->and($pipeline->fresh()->current_stage)->toBe('next');
    });

    it('is a no-op for a step that already finished', function (PipelineStepStatus $status): void {
        $pipeline = pipelineWith();
        $step = stepFor($pipeline, $status);

        $this->transitions->skip($pipeline, $step);

        expect($step->fresh()->status)->toBe($status);
    })->with([
        'completed' => [PipelineStepStatus::Completed],
        'skipped' => [PipelineStepStatus::Skipped],
    ]);

    it('refuses to skip a failed or stuck step', function (PipelineStepStatus $status): void {
        $pipeline = pipelineWith();
        $step = stepFor($pipeline, $status);

        expect(fn () => $this->transitions->skip($pipeline, $step))
            ->toThrow(ValidationException::class);
    })->with([
        'failed' => [PipelineStepStatus::Failed],
        'stuck' => [PipelineStepStatus::Stuck],
    ]);
});

describe('fail', function (): void {
    it('records the error on both the step and the pipeline', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Running);
        $step = stepFor($pipeline, PipelineStepStatus::Running);

        $this->transitions->fail($pipeline, $step, 'Textract timed out', 'E_TIMEOUT');

        expect($step->fresh())
            ->status->toBe(PipelineStepStatus::Failed)
            ->error_code->toBe('E_TIMEOUT')
            ->failed_at->not->toBeNull()
            ->and($pipeline->fresh())
            ->status->toBe(PipelineStatus::Failed)
            ->error_message->toBe('Textract timed out');
    });

    it('is a no-op for a step that already finished', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Running);
        $step = stepFor($pipeline, PipelineStepStatus::Completed);

        $this->transitions->fail($pipeline, $step, 'late failure', 'E_LATE');

        expect($step->fresh()->status)->toBe(PipelineStepStatus::Completed)
            // Crucially the pipeline is not dragged down with it.
            ->and($pipeline->fresh()->status)->toBe(PipelineStatus::Running);
    });

    it('refuses to fail a completed pipeline', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Completed);
        $step = stepFor($pipeline, PipelineStepStatus::Running);

        expect(fn () => $this->transitions->fail($pipeline, $step, 'too late', 'E_LATE'))
            ->toThrow(ValidationException::class);
    });
});

describe('stuck', function (): void {
    it('marks the step and pipeline stuck without a failed_at', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Running);
        $step = stepFor($pipeline, PipelineStepStatus::Running);

        $this->transitions->stuck($pipeline, $step, 'No heartbeat', 'E_STALE');

        expect($step->fresh())
            ->status->toBe(PipelineStepStatus::Stuck)
            ->failed_at->toBeNull()
            ->and($pipeline->fresh())
            ->status->toBe(PipelineStatus::Stuck)
            ->error_code->toBe('E_STALE');
    });

    it('is a no-op for a step that already finished', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Running);
        $step = stepFor($pipeline, PipelineStepStatus::Skipped);

        $this->transitions->stuck($pipeline, $step, 'stale', 'E_STALE');

        expect($step->fresh()->status)->toBe(PipelineStepStatus::Skipped);
    });

    it('refuses to mark a closed pipeline stuck', function (PipelineStatus $status): void {
        $pipeline = pipelineWith($status);

        expect(fn () => $this->transitions->stuckPipeline($pipeline, 'stale', 'E_STALE'))
            ->toThrow(ValidationException::class);
    })->with([
        'completed' => [PipelineStatus::Completed],
        'cancelled' => [PipelineStatus::Cancelled],
    ]);
});

describe('retry', function (): void {
    it('reopens a closed run and counts the attempt', function (PipelineStatus $status): void {
        $pipeline = Pipeline::factory()->failed()->create([
            'tenant_id' => $this->tenant->id,
            'status' => $status,
        ]);

        $this->transitions->retry($pipeline);

        expect($pipeline->fresh())
            ->status->toBe(PipelineStatus::Pending)
            ->attempt_number->toBe(2);
    })->with([
        'failed' => [PipelineStatus::Failed],
        'stuck' => [PipelineStatus::Stuck],
    ]);

    it('clears the diagnostics left behind by the previous attempt', function (): void {
        $pipeline = Pipeline::factory()->failed()->create([
            'tenant_id' => $this->tenant->id,
            'next_retry_at' => now()->addMinute(),
            'completed_at' => now()->subMinute(),
        ]);

        $this->transitions->retry($pipeline);

        expect($pipeline->fresh())
            ->current_stage->toBeNull()
            ->error_code->toBeNull()
            ->error_message->toBeNull()
            ->started_at->toBeNull()
            ->next_retry_at->toBeNull()
            ->completed_at->toBeNull()
            ->failed_at->toBeNull();
    });

    it('drops the provider job id so a late provider result cannot claim the run', function (): void {
        $pipeline = Pipeline::factory()->failed()->create(['tenant_id' => $this->tenant->id]);

        expect($pipeline->provider_job_id)->not->toBeNull();

        $this->transitions->retry($pipeline);

        expect($pipeline->fresh()->provider_job_id)->toBeNull();
    });

    it('resets every step to pending regardless of how it ended', function (): void {
        $pipeline = Pipeline::factory()->failed()->create(['tenant_id' => $this->tenant->id]);
        stepFor($pipeline, PipelineStepStatus::Completed, 'submit', 0);
        stepFor($pipeline, PipelineStepStatus::Failed, 'provider_processing', 1);
        stepFor($pipeline, PipelineStepStatus::Skipped, 'process_results', 2);

        $this->transitions->retry($pipeline);

        $statuses = $pipeline->fresh()->steps->pluck('status')->all();

        expect($statuses)
            ->toHaveCount(3)
            ->each->toBe(PipelineStepStatus::Pending);
    });

    it('clears step diagnostics but keeps attempts cumulative', function (): void {
        $pipeline = Pipeline::factory()->failed()->create(['tenant_id' => $this->tenant->id]);
        $step = stepFor($pipeline, PipelineStepStatus::Failed, 'submit', 0);
        $step->forceFill([
            'attempts' => 3,
            'error_code' => 'E_BOOM',
            'error_message' => 'boom',
            'started_at' => now()->subMinutes(9),
            'last_activity_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(5),
            'failed_at' => now()->subMinutes(5),
        ])->saveQuietly();

        $this->transitions->retry($pipeline);

        expect($step->fresh())
            ->status->toBe(PipelineStepStatus::Pending)
            ->attempts->toBe(3)
            ->error_code->toBeNull()
            ->error_message->toBeNull()
            ->started_at->toBeNull()
            ->last_activity_at->toBeNull()
            ->completed_at->toBeNull()
            ->failed_at->toBeNull();
    });

    it('refuses to retry a run that is not failed or stuck', function (PipelineStatus $status): void {
        $pipeline = pipelineWith($status);

        expect(fn () => $this->transitions->retry($pipeline))
            ->toThrow(ValidationException::class);
    })->with([
        'pending' => [PipelineStatus::Pending],
        'running' => [PipelineStatus::Running],
        'completed' => [PipelineStatus::Completed],
        'cancelled' => [PipelineStatus::Cancelled],
    ]);

    it('leaves a rejected run completely untouched', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Completed);
        $step = stepFor($pipeline, PipelineStepStatus::Completed);

        expect(fn () => $this->transitions->retry($pipeline))
            ->toThrow(ValidationException::class)
            ->and($pipeline->fresh())->status->toBe(PipelineStatus::Completed)->attempt_number->toBe(1)
            ->and($step->fresh()->status)
            ->toBe(PipelineStepStatus::Completed);
    });
});

describe('heartbeat', function (): void {
    it('touches last activity on the step and pipeline', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Running);
        $step = stepFor($pipeline, PipelineStepStatus::Running);
        $pipeline->forceFill(['last_activity_at' => now()->subHour()])->saveQuietly();

        $this->transitions->heartbeat($pipeline, $step);

        expect($pipeline->fresh()->last_activity_at->timestamp)
            ->toBe(now()->timestamp);
    });

    it('is a no-op for a step that already finished', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Running);
        $step = stepFor($pipeline, PipelineStepStatus::Completed);
        $step->forceFill(['last_activity_at' => now()->subHour()])->saveQuietly();

        $this->transitions->heartbeat($pipeline, $step);

        expect($step->fresh()->last_activity_at->timestamp)
            ->toBe(now()->subHour()->timestamp);
    });

    it('refuses to heartbeat a step on a closed pipeline', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Cancelled);
        $step = stepFor($pipeline, PipelineStepStatus::Running);

        expect(fn () => $this->transitions->heartbeat($pipeline, $step))
            ->toThrow(ValidationException::class);
    });

    it('leaves a terminal pipeline untouched', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Completed);
        $pipeline->forceFill(['last_activity_at' => now()->subHour()])->saveQuietly();

        $this->transitions->heartbeatPipeline($pipeline);

        expect($pipeline->fresh()->last_activity_at->timestamp)
            ->toBe(now()->subHour()->timestamp);
    });

    it('touches a live pipeline', function (): void {
        $pipeline = pipelineWith(PipelineStatus::Running);
        $pipeline->forceFill(['last_activity_at' => now()->subHour()])->saveQuietly();

        $this->transitions->heartbeatPipeline($pipeline);

        expect($pipeline->fresh()->last_activity_at->timestamp)->toBe(now()->timestamp);
    });
});
