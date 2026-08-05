<?php

declare(strict_types=1);

use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Policies\PipelinePolicy;
use App\Tenancy\Enums\Role;

test('owner may retry a failed or stuck run', function (PipelineStatus $status): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
    ]);

    expect((new PipelinePolicy)->retry($owner, $pipeline))->toBeTrue();
})->with([
    'failed' => PipelineStatus::Failed,
    'stuck' => PipelineStatus::Stuck,
]);

test('roles holding pipelines.retry may retry a failed run', function (Role $role): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, $role);

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Failed,
    ]);

    expect((new PipelinePolicy)->retry($member, $pipeline))->toBeTrue();
})->with([
    'administrator' => Role::Administrator,
    'adviser' => Role::Adviser,
]);

test('roles without pipelines.retry may not retry a failed run', function (Role $role): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, $role);

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Failed,
    ]);

    expect((new PipelinePolicy)->retry($member, $pipeline))->toBeFalse();
})->with([
    'reviewer' => Role::Reviewer,
    'read only' => Role::ReadOnly,
]);

test('a run that is not failed or stuck may never be retried', function (PipelineStatus $status): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
    ]);

    expect((new PipelinePolicy)->retry($owner, $pipeline))->toBeFalse();
})->with([
    'pending' => PipelineStatus::Pending,
    'running' => PipelineStatus::Running,
    'completed' => PipelineStatus::Completed,
    'cancelled' => PipelineStatus::Cancelled,
]);

test('a failed run in another tenant may not be retried', function (): void {
    ['owner' => $owner] = provisionWorkspace();
    ['tenant' => $otherTenant] = provisionWorkspace('Other firm');

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $otherTenant->id,
        'status' => PipelineStatus::Failed,
    ]);

    expect((new PipelinePolicy)->retry($owner, $pipeline))->toBeFalse();
});
