<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Policies\PipelinePolicy;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;

covers(PipelinePolicy::class);

test('owner may view pipelines in the current workspace', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    expect((new PipelinePolicy)->viewAny($owner))->toBeTrue()
        ->and((new PipelinePolicy)->view($owner, $pipeline))->toBeTrue();
});

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

test('owner may cancel a non-terminal run', function (PipelineStatus $status): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
    ]);

    expect((new PipelinePolicy)->cancel($owner, $pipeline))->toBeTrue();
})->with([
    'pending' => PipelineStatus::Pending,
    'running' => PipelineStatus::Running,
]);

test('owner may not cancel a terminal run', function (PipelineStatus $status): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
    ]);

    expect((new PipelinePolicy)->cancel($owner, $pipeline))->toBeFalse();
})->with([
    'completed' => PipelineStatus::Completed,
    'failed' => PipelineStatus::Failed,
    'stuck' => PipelineStatus::Stuck,
    'cancelled' => PipelineStatus::Cancelled,
]);

test('roles without pipelines.cancel may not cancel an open run', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Running,
    ]);

    expect((new PipelinePolicy)->cancel($reader, $pipeline))->toBeFalse();
});

test('owner may delete a terminal run', function (PipelineStatus $status): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
    ]);

    expect((new PipelinePolicy)->delete($owner, $pipeline))->toBeTrue();
})->with([
    'completed' => PipelineStatus::Completed,
    'failed' => PipelineStatus::Failed,
    'stuck' => PipelineStatus::Stuck,
    'cancelled' => PipelineStatus::Cancelled,
]);

test('owner may not delete a non-terminal run', function (PipelineStatus $status): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
    ]);

    expect((new PipelinePolicy)->delete($owner, $pipeline))->toBeFalse();
})->with([
    'pending' => PipelineStatus::Pending,
    'running' => PipelineStatus::Running,
]);

test('owner may download a completed dossier export pipeline', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $export = pipelinePolicyExport($tenant, completed: true);

    $pipeline = Pipeline::factory()->dossierExport()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Completed,
        'pipelineable_type' => $export->getMorphClass(),
        'pipelineable_id' => $export->id,
    ]);

    expect((new PipelinePolicy)->download($owner, $pipeline))->toBeTrue();
});

test('non-export pipelines are never downloadable', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => PipelineType::OcrDocument,
        'status' => PipelineStatus::Completed,
    ]);

    expect($pipeline->type)->not->toBe(PipelineType::DossierExport)
        ->and((new PipelinePolicy)->download($owner, $pipeline))->toBeFalse();
});

test('read-only members may not download a dossier export pipeline', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $export = pipelinePolicyExport($tenant, completed: true);

    $pipeline = Pipeline::factory()->dossierExport()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Completed,
        'pipelineable_type' => $export->getMorphClass(),
        'pipelineable_id' => $export->id,
    ]);

    expect((new PipelinePolicy)->download($reader, $pipeline))->toBeFalse();
});

test('a pending dossier export is not downloadable yet', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    // Pending exports have no path — isDownloadable() must stay false.
    $export = pipelinePolicyExport($tenant, completed: false);

    $pipeline = Pipeline::factory()->dossierExport()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Running,
        'pipelineable_type' => $export->getMorphClass(),
        'pipelineable_id' => $export->id,
    ]);

    expect($export->isDownloadable())->toBeFalse()
        ->and((new PipelinePolicy)->download($owner, $pipeline))->toBeFalse();
});

test('download requires the dossier-export pipeline type even when the subject is an export', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $export = pipelinePolicyExport($tenant, completed: true);

    // Without the type guard, a completed export subject would incorrectly authorize.
    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => PipelineType::OcrDocument,
        'status' => PipelineStatus::Completed,
        'pipelineable_type' => $export->getMorphClass(),
        'pipelineable_id' => $export->id,
    ]);

    expect((new PipelinePolicy)->download($owner, $pipeline))->toBeFalse();
});

test('a dossier-export pipeline whose subject is not an export is not downloadable', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    // Factory default subject is an UploadedDocument — instanceof DossierExport must fail.
    $pipeline = Pipeline::factory()->dossierExport()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Completed,
    ]);

    expect((new PipelinePolicy)->download($owner, $pipeline))->toBeFalse();
});

function pipelinePolicyExport(Tenant $tenant, bool $completed): DossierExport
{
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $factory = DossierExport::factory()->state([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    return $completed
        ? $factory->completed()->create()
        : $factory->create();
}
