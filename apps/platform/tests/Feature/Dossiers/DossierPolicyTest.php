<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Policies\DossierPolicy;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;

covers(DossierPolicy::class);

/**
 * @return array{policy: DossierPolicy, owner: User, tenant: Tenant, dossier: Dossier}
 */
function dossierPolicyContext(DossierStatus $status = DossierStatus::Draft): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => $status,
    ]);

    return [
        'policy' => new DossierPolicy,
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ];
}

test('owner can manage and follow up a draft dossier', function (): void {
    $context = dossierPolicyContext();
    $policy = $context['policy'];
    $owner = $context['owner'];
    $dossier = $context['dossier'];

    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->view($owner, $dossier))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue()
        ->and($policy->update($owner, $dossier))->toBeTrue()
        ->and($policy->restore($owner, $dossier))->toBeTrue()
        ->and($policy->sendReminder($owner, $dossier))->toBeTrue()
        ->and($policy->rollOver($owner, $dossier))->toBeTrue()
        ->and($policy->delete($owner, $dossier))->toBeTrue();
});

// Completed dossiers still allow delete/view, but block status-gated mutations.
test('completed dossiers deny status-gated mutations', function (string $ability): void {
    $context = dossierPolicyContext(DossierStatus::Completed);
    $policy = $context['policy'];
    $owner = $context['owner'];
    $dossier = $context['dossier'];

    expect($policy->{$ability}($owner, $dossier))->toBeFalse()
        ->and($policy->delete($owner, $dossier))->toBeTrue();
})->with([
    'update' => 'update',
    'sendReminder' => 'sendReminder',
    'complete' => 'complete',
]);

test('read-only members can view dossiers but cannot create or complete', function (): void {
    $context = dossierPolicyContext();
    $policy = $context['policy'];
    $tenant = $context['tenant'];
    $dossier = $context['dossier'];

    $reader = workspaceMember($tenant, Role::ReadOnly);

    expect($policy->viewAny($reader))->toBeTrue()
        ->and($policy->view($reader, $dossier))->toBeTrue()
        ->and($policy->create($reader))->toBeFalse()
        ->and($policy->restore($reader, $dossier))->toBeFalse()
        ->and($policy->sendReminder($reader, $dossier))->toBeFalse()
        ->and($policy->rollOver($reader, $dossier))->toBeFalse()
        ->and($policy->complete($reader, $dossier))->toBeFalse();
});

test('completed dossiers can still be rolled over', function (): void {
    $context = dossierPolicyContext(DossierStatus::Completed);

    expect($context['policy']->rollOver($context['owner'], $context['dossier']))->toBeTrue();
});

test('reviewers cannot roll a dossier over', function (): void {
    $context = dossierPolicyContext();
    $reviewer = workspaceMember($context['tenant'], Role::Reviewer);

    expect($context['policy']->rollOver($reviewer, $context['dossier']))->toBeFalse();
});

test('users outside the tenant cannot view the dossier', function (): void {
    $context = dossierPolicyContext();
    $policy = $context['policy'];
    $dossier = $context['dossier'];
    $outsider = User::factory()->create();

    expect($policy->view($outsider, $dossier))->toBeFalse();
});
