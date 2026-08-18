<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\DeletePipelinesForSubjectsAction;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Models\Tenant;

covers(DeletePipelinesForSubjectsAction::class);

test('deleting pipelines for subjects leaves another tenant\'s runs untouched', function (): void {
    ['tenant' => $first] = provisionWorkspace('First Firm');
    ['tenant' => $second] = provisionWorkspace('Second Firm');

    [$firstDocument, $firstPipeline] = subjectWithPipeline($first);
    [, $secondPipeline] = subjectWithPipeline($second);

    actingInWorkspace($first);

    expect(resolve(DeletePipelinesForSubjectsAction::class)->handle([$firstDocument]))->toBe(1)
        ->and(Pipeline::queryWithoutTenantScope()->whereKey($firstPipeline->id)->exists())->toBeFalse()
        ->and(Pipeline::queryWithoutTenantScope()->whereKey($secondPipeline->id)->exists())->toBeTrue();
});

test('deleting pipelines for subjects is tenant scoped, so it deletes nothing without a current tenant', function (): void {
    ['tenant' => $tenant] = provisionWorkspace('Scoped Firm');

    [$document, $pipeline] = subjectWithPipeline($tenant);

    Tenant::forgetCurrent();

    // Terminating the delete on the base query builder would bypass TenantScope
    // and remove the row regardless of which tenant is current.
    expect(resolve(DeletePipelinesForSubjectsAction::class)->handle([$document]))->toBe(0)
        ->and(Pipeline::queryWithoutTenantScope()->whereKey($pipeline->id)->exists())->toBeTrue();
});

/**
 * @return array{0: UploadedDocument, 1: Pipeline}
 */
function subjectWithPipeline(Tenant $tenant): array
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
    ]);

    return [$document, $pipeline];
}
