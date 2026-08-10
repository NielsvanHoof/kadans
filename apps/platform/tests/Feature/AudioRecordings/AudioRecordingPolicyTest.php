<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Policies\AudioRecordingPolicy;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;

covers(AudioRecordingPolicy::class);

/**
 * @return array{owner: User, tenant: Tenant, dossier: Dossier, audioRecording: AudioRecording, policy: AudioRecordingPolicy}
 */
function audioRecordingPolicyContext(
    DossierStatus $status = DossierStatus::InReview,
): array {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => $status,
    ]);
    $audioRecording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
        'audioRecording' => $audioRecording,
        'policy' => new AudioRecordingPolicy,
    ];
}

test('owner can view, download, create, and delete audio recordings on an open dossier', function (): void {
    [
        'owner' => $owner,
        'dossier' => $dossier,
        'audioRecording' => $audioRecording,
        'policy' => $policy,
    ] = audioRecordingPolicyContext();

    expect($policy->view($owner, $audioRecording))->toBeTrue()
        ->and($policy->download($owner, $audioRecording))->toBeTrue()
        ->and($policy->create($owner, $dossier))->toBeTrue()
        ->and($policy->delete($owner, $audioRecording))->toBeTrue();
});

test('reviewers can view recordings but cannot upload, download, or delete them', function (): void {
    [
        'tenant' => $tenant,
        'dossier' => $dossier,
        'audioRecording' => $audioRecording,
        'policy' => $policy,
    ] = audioRecordingPolicyContext();
    $reviewer = workspaceMember($tenant, Role::Reviewer);

    expect($policy->view($reviewer, $audioRecording))->toBeTrue()
        ->and($policy->download($reviewer, $audioRecording))->toBeFalse()
        ->and($policy->create($reviewer, $dossier))->toBeFalse()
        ->and($policy->delete($reviewer, $audioRecording))->toBeFalse();
});

test('uploading and deleting are blocked once the dossier is completed', function (): void {
    [
        'owner' => $owner,
        'dossier' => $dossier,
        'audioRecording' => $audioRecording,
        'policy' => $policy,
    ] = audioRecordingPolicyContext(DossierStatus::Completed);

    expect($policy->create($owner, $dossier))->toBeFalse()
        ->and($policy->delete($owner, $audioRecording))->toBeFalse()
        ->and($policy->view($owner, $audioRecording))->toBeTrue();
});

test('delete authorization works for recordings hydrated in a multi-model collection', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::InReview,
    ]);

    AudioRecording::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    // Re-query as a collection so preventsLazyLoading is set; automatic eager
    // loading should still resolve dossier without throwing.
    $recordings = AudioRecording::query()->where('dossier_id', $dossier->id)->get();
    $policy = new AudioRecordingPolicy;

    expect($recordings)->toHaveCount(2)
        ->and($recordings->first()?->preventsLazyLoading)->toBeTrue()
        ->and($policy->delete($owner, $recordings[0]))->toBeTrue()
        ->and($policy->delete($owner, $recordings[1]))->toBeTrue();
});
