<?php

declare(strict_types=1);

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Reporting\Queries\Dashboard\FetchDashboardProcessingHealthQuery;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

covers(FetchDashboardProcessingHealthQuery::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function processingHealth(object $tenant): object
{
    return resolve(FetchDashboardProcessingHealthQuery::class)->handle($tenant);
}

test('only runs that need a human are counted', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Failed]);
    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Failed]);
    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Stuck]);
    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Completed]);
    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Running]);
    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Cancelled]);

    $health = processingHealth($tenant);

    $recentStatuses = array_map(
        static fn (object $row): PipelineStatus => $row->status,
        $health->recent,
    );

    // Counters stay failed/stuck only. The row list is every retryable run,
    // which now includes Cancelled as well as Failed and Stuck.
    expect($health->failed)->toBe(2)
        ->and($health->stuck)->toBe(1)
        ->and($recentStatuses)->toHaveCount(4)
        ->and($recentStatuses)->toContain(PipelineStatus::Cancelled)
        ->and($recentStatuses)->not->toContain(PipelineStatus::Completed)
        ->and($recentStatuses)->not->toContain(PipelineStatus::Running);
});

test('a healthy workspace skips the row query entirely', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Completed]);

    $health = processingHealth($tenant);

    expect($health->failed)->toBe(0)
        ->and($health->stuck)->toBe(0)
        ->and($health->recent)->toBeEmpty();
});

test('rows carry the detail the card links and labels with', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => PipelineType::AudioTranscription,
        'status' => PipelineStatus::Failed,
        'error_message' => 'Provider returned 500',
        'last_activity_at' => now()->subHour(),
    ]);

    $row = processingHealth($tenant)->recent[0];

    expect($row->type)->toBe(PipelineType::AudioTranscription)
        ->and($row->status)->toBe(PipelineStatus::Failed)
        ->and($row->errorMessage)->toBe('Provider returned 500')
        ->and($row->lastActivityAt)->not->toBeNull()
        ->and($row->isRetryable)->toBeTrue()
        ->and($row->subject->type)->toBeString()
        ->and($row->subject->id)->toBeInt();
});

test('a long provider error is truncated rather than shipped whole', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Failed,
        'error_message' => str_repeat('a', 5000),
    ]);

    $errorMessage = (string) processingHealth($tenant)->recent[0]->errorMessage;

    // Bounded payload, and visibly cut rather than silently clipped. The bound
    // is the excerpt length plus Str::limit's trailing ellipsis.
    expect(mb_strlen($errorMessage))->toBeLessThan(200)
        ->and($errorMessage)->toEndWith('...');
});

test('another firms failures stay invisible', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    ['tenant' => $foreignTenant] = provisionWorkspace('Other firm');
    actingInWorkspace($foreignTenant);
    Pipeline::factory()->create(['tenant_id' => $foreignTenant->id, 'status' => PipelineStatus::Failed]);

    actingInWorkspace($tenant);

    expect(processingHealth($tenant)->failed)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Permission gating
|--------------------------------------------------------------------------
|
| The prop is withheld server-side, not merely hidden in the UI: a member
| without pipelines.view never receives processing data at all.
|
*/

test('a member who may view pipelines is offered the deferred prop', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Failed]);

    actingInWorkspace($tenant);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('can_view_processing_health', true)
            ->loadDeferredProps('signals', fn (Assert $deferred): Assert => $deferred
                ->where('processing_health.failed', 1)));
});

test('a member without pipelines access never receives processing data', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $readOnly = workspaceMember($tenant, Role::ReadOnly);

    Pipeline::factory()->create(['tenant_id' => $tenant->id, 'status' => PipelineStatus::Failed]);

    actingInWorkspace($tenant);

    $this->actingAs($readOnly)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('can_view_processing_health', false)
            ->loadDeferredProps('signals', fn (Assert $deferred): Assert => $deferred
                ->missing('processing_health')));
});
