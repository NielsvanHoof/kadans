<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierBuilderLock;
use App\Tenancy\Enums\Role;
use Illuminate\Support\Str;

/**
 * @return array{owner: User, tenant: mixed, dossier: Dossier}
 */
function createBuilderLockContext(): array
{
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace(owner: $owner);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Lock me',
        'status' => DossierStatus::Draft,
    ]);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ];
}

test('opening the builder acquires the lock for the visitor', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createBuilderLockContext();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/dossiers/builder')
            ->where('builder_lock.is_mine', true)
            ->where('builder_lock.holder.name', 'Alice Owner')
            ->has('builder_lock.token')
            ->has('builder_lock.expires_at'));

    $dossier->refresh();

    expect($dossier->isBuilderLockedBy($owner))->toBeTrue()
        ->and($dossier->builder_lock_token)->not->toBeNull();
});

test('second adviser sees locked by holder and cannot sync', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createBuilderLockContext();

    grantDossierBuilderLock($dossier, $owner);

    $adviser = workspaceMember($tenant, Role::Adviser);
    $adviser->forceFill(['name' => 'Bob Adviser'])->save();

    $this->actingAs($adviser)
        ->get(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('builder_lock.is_mine', false)
            ->where('builder_lock.token', null)
            ->where('builder_lock.holder.name', 'Alice Owner'));

    $this->actingAs($adviser)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [],
        ])
        ->assertForbidden();
});

test('heartbeat extends the lock expiry for the holder', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createBuilderLockContext();

    $token = grantDossierBuilderLock($dossier, $owner);
    $dossier->forceFill([
        'builder_lock_expires_at' => now()->addSeconds(30),
    ])->save();

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.dossiers.builder-lock.heartbeat', $tenant, [
            'dossier' => $dossier,
        ]), [
            'token' => $token,
        ])
        ->assertOk()
        ->assertJsonPath('builder_lock.is_mine', true);

    $dossier->refresh();

    expect($dossier->builder_lock_expires_at?->greaterThan(now()->addSeconds(DossierBuilderLock::TTL_SECONDS - 10)))
        ->toBeTrue();
});

test('release clears the lock for the holder token', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createBuilderLockContext();

    $token = grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.dossiers.builder-lock.destroy', $tenant, [
            'dossier' => $dossier,
        ]), [
            'token' => $token,
        ])
        ->assertOk()
        ->assertJsonPath('builder_lock.is_mine', false)
        ->assertJsonPath('builder_lock.holder', null);

    expect($dossier->fresh()->hasActiveBuilderLock())->toBeFalse();
});

test('expired lock can be acquired by another adviser', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createBuilderLockContext();

    $dossier->forceFill([
        'builder_locked_by_user_id' => $owner->id,
        'builder_lock_token' => (string) Str::ulid(),
        'builder_lock_expires_at' => now()->subMinute(),
    ])->save();

    $adviser = workspaceMember($tenant, Role::Adviser);
    $adviser->forceFill(['name' => 'Bob Adviser'])->save();

    $this->actingAs($adviser)
        ->postJson(workspaceRoute('workspaces.dossiers.builder-lock.store', $tenant, [
            'dossier' => $dossier,
        ]))
        ->assertOk()
        ->assertJsonPath('builder_lock.is_mine', true)
        ->assertJsonPath('builder_lock.holder.name', 'Bob Adviser');

    expect($dossier->fresh()->isBuilderLockedBy($adviser))->toBeTrue();
});

test('same user re-entering the builder refreshes their own lock', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createBuilderLockContext();

    $token = grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('builder_lock.is_mine', true)
            ->where('builder_lock.token', $token));
});
