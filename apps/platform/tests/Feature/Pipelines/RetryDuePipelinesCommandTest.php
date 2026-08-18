<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Console\Commands\RetryDuePipelinesCommand;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineRetryBackoff;
use App\Pipelines\Support\PipelineState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

covers(RetryDuePipelinesCommand::class);

/*
|--------------------------------------------------------------------------
| Automatic retry
|--------------------------------------------------------------------------
|
| A failed run books its own next attempt on an exponential backoff, and
| pipelines:retry-due picks up whatever is due. The attempt cap is the safety
| valve: past it a run stops retrying itself and waits for a human, so a
| permanently broken document cannot churn the queue forever.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'ocr.driver' => 'mock',
        'pipelines.retry.max_attempts' => 3,
        'pipelines.retry.base_delay_minutes' => 5,
        'pipelines.retry.multiplier' => 4,
        'pipelines.retry.max_delay_minutes' => 240,
    ]);

    ['tenant' => $tenant] = provisionWorkspace();
    $this->tenant = $tenant;
});

describe('backoff', function (): void {
    it('grows exponentially per attempt and stops at the ceiling', function (): void {
        $backoff = resolve(PipelineRetryBackoff::class);

        expect($backoff->delayMinutes(1))->toBe(5)
            ->and($backoff->delayMinutes(2))->toBe(20)
            ->and($backoff->delayMinutes(3))->toBe(80)
            ->and($backoff->delayMinutes(10))->toBe(240);
    });

    it('books the next attempt when a run fails inside the budget', function (): void {
        $pipeline = duePipeline();

        resolve(PipelineState::class)->fail($pipeline, errorCode: 'boom', errorMessage: 'Boom.');

        expect($pipeline->fresh())
            ->status->toBe(PipelineStatus::Failed)
            ->and($pipeline->fresh()->next_retry_at?->timestamp)
            ->toEqualWithDelta(now()->addMinutes(5)->timestamp, 5);
    });

    it('books nothing once a run has spent its automatic attempts', function (): void {
        $pipeline = duePipeline();
        $pipeline->forceFill(['attempt_number' => 3])->saveQuietly();

        resolve(PipelineState::class)->fail($pipeline->fresh(), errorCode: 'boom', errorMessage: 'Boom.');

        expect($pipeline->fresh())
            ->status->toBe(PipelineStatus::Failed)
            ->next_retry_at->toBeNull();
    });
});

describe('pipelines:retry-due', function (): void {
    it('re-queues a run whose backoff has elapsed', function (): void {
        Queue::fake();
        $pipeline = failedPipelineDueAt(now()->subMinute());

        Artisan::call('pipelines:retry-due');

        expect(Artisan::output())->toContain('Retried: 1. Skipped: 0.')
            ->and($pipeline->fresh())
            ->status->toBe(PipelineStatus::Pending)
            ->attempt_number->toBe(2)
            ->next_retry_at->toBeNull();

        Queue::assertPushed(
            ProcessUploadedDocumentJob::class,
            fn (ProcessUploadedDocumentJob $job): bool => $job->pipelineId === $pipeline->id,
        );
    });

    it('leaves a run whose backoff has not elapsed alone', function (): void {
        Queue::fake();
        $pipeline = failedPipelineDueAt(now()->addHour());

        Artisan::call('pipelines:retry-due');

        expect(Artisan::output())->toContain('Retried: 0.')
            ->and($pipeline->fresh())
            ->status->toBe(PipelineStatus::Failed)
            ->attempt_number->toBe(1);

        Queue::assertNotPushed(ProcessUploadedDocumentJob::class);
    });

    it('leaves a run that has spent its automatic attempts for a human', function (): void {
        Queue::fake();
        $pipeline = failedPipelineDueAt(now()->subMinute());
        $pipeline->forceFill(['attempt_number' => 3])->saveQuietly();

        Artisan::call('pipelines:retry-due');

        expect(Artisan::output())->toContain('Retried: 0.')
            ->and($pipeline->fresh()->status)->toBe(PipelineStatus::Failed);

        Queue::assertNotPushed(ProcessUploadedDocumentJob::class);
    });

    it('records the retry as automatic, with no causer', function (): void {
        Queue::fake();
        $pipeline = failedPipelineDueAt(now()->subMinute());

        Artisan::call('pipelines:retry-due');

        $activity = Activity::query()
            ->where('event', AuditEvent::PipelineRetried->value)
            ->where('subject_id', $pipeline->id)
            ->sole();

        expect($activity->causer_id)->toBeNull()
            ->and($activity->getProperty('automatic'))->toBeTrue();
    });

    it('counts per tenant rather than globally', function (): void {
        Queue::fake();
        failedPipelineDueAt(now()->subMinute());

        ['tenant' => $second] = provisionWorkspace('Second Firm');
        $this->tenant = $second;
        failedPipelineDueAt(now()->subMinute());

        Artisan::call('pipelines:retry-due');

        // Two tenants, one due run each: three would mean a scope leak.
        expect(Artisan::output())->toContain('Retried: 2. Skipped: 0.');
    });
});

function duePipeline(): Pipeline
{
    $tenant = test()->tenant;

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $document = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => PipelineType::OcrDocument,
        'status' => PipelineStatus::Running,
        'pipelineable_type' => $document->getMorphClass(),
        'pipelineable_id' => $document->id,
    ]);

    foreach (PipelineType::OcrDocument->stepKeys() as $position => $key) {
        $pipeline->steps()->create([
            'tenant_id' => $tenant->id,
            'key' => $key,
            'position' => $position,
        ]);
    }

    return $pipeline->fresh();
}

function failedPipelineDueAt(DateTimeInterface $dueAt): Pipeline
{
    $pipeline = duePipeline();

    $pipeline->forceFill([
        'status' => PipelineStatus::Failed,
        'error_code' => 'ocr_job_failed',
        'error_message' => 'The processing job failed.',
        'failed_at' => now()->subMinutes(10),
        'next_retry_at' => $dueAt,
    ])->saveQuietly();

    return $pipeline->fresh();
}
