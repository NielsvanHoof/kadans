<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Console\Commands\MonitorPipelinesCommand;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Spatie\Multitenancy\Events\MadeTenantCurrentEvent;

covers(MonitorPipelinesCommand::class);

test('pipelines:monitor reports each tenant\'s own counts rather than global totals', function (): void {
    ['tenant' => $first] = provisionWorkspace('First Firm');
    ['tenant' => $second] = provisionWorkspace('Second Firm');

    monitoredPipeline($first, PipelineStatus::Pending);
    monitoredPipeline($first, PipelineStatus::Running, ['last_activity_at' => now()]);
    monitoredPipeline($first, PipelineStatus::Failed, ['attempt_number' => 2]);

    monitoredPipeline($second, PipelineStatus::Pending);
    monitoredPipeline($second, PipelineStatus::Failed, ['attempt_number' => 3]);
    monitoredPipeline($second, PipelineStatus::Failed, ['attempt_number' => 4]);

    Artisan::call('pipelines:monitor');

    // Terminating the count on the base query builder would skip TenantScope and
    // report 3 and 3 once per tenant, summing to 6 and 6.
    expect(Artisan::output())->toContain('Active: 3. Marked stuck: 0. Repeatedly failing: 3.');
});

test('pipelines:monitor marks an inactive run stuck without touching another tenant\'s fresh run', function (): void {
    ['tenant' => $first] = provisionWorkspace('First Firm');
    ['tenant' => $second] = provisionWorkspace('Second Firm');

    $inactive = monitoredPipeline($first, PipelineStatus::Running, [
        'current_stage' => PipelineStepKey::Submit,
        'started_at' => now()->subHours(2),
        'last_activity_at' => now()->subHours(2),
    ]);
    $fresh = monitoredPipeline($second, PipelineStatus::Running, [
        'current_stage' => PipelineStepKey::Submit,
        'started_at' => now()->subMinute(),
        'last_activity_at' => now()->subMinute(),
    ]);

    Artisan::call('pipelines:monitor');

    expect(Artisan::output())->toContain('Marked stuck: 1.')
        ->and(Pipeline::queryWithoutTenantScope()->findOrFail($inactive->id)->status)
        ->toBe(PipelineStatus::Stuck)
        ->and(Pipeline::queryWithoutTenantScope()->findOrFail($fresh->id)->status)
        ->toBe(PipelineStatus::Running);
});

test('pipelines:monitor does not enter idle tenants', function (): void {
    ['tenant' => $busy] = provisionWorkspace('Busy Firm');
    ['tenant' => $idle] = provisionWorkspace('Idle Firm');

    monitoredPipeline($busy, PipelineStatus::Running, [
        'current_stage' => PipelineStepKey::Submit,
        'started_at' => now()->subHours(2),
        'last_activity_at' => now()->subHours(2),
    ]);

    // Drop leftover current tenant so makeCurrent actually fires for $busy,
    // and so execute()'s finally block does not restore $idle as current.
    Tenant::forgetCurrent();
    Event::fake([MadeTenantCurrentEvent::class]);

    Artisan::call('pipelines:monitor');

    Event::assertDispatched(fn (MadeTenantCurrentEvent $event): bool => $event->tenant->is($busy));
    Event::assertNotDispatched(
        MadeTenantCurrentEvent::class,
        fn (MadeTenantCurrentEvent $event): bool => $event->tenant->is($idle),
    );
    expect(Artisan::output())->toContain('Marked stuck: 1.');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function monitoredPipeline(Tenant $tenant, PipelineStatus $status, array $attributes = []): Pipeline
{
    actingInWorkspace($tenant);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $request = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $document = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $request->id,
    ]);

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'pipelineable_type' => $document->getMorphClass(),
        'pipelineable_id' => $document->id,
        'status' => $status,
        ...$attributes,
    ]);

    // Real runs always carry their type's steps; the stuck path locks one by key.
    foreach ($pipeline->type->stepKeys() as $position => $key) {
        PipelineStep::factory()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'key' => $key,
            'position' => $position,
        ]);
    }

    return $pipeline;
}
