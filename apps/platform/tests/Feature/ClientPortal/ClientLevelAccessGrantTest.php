<?php

declare(strict_types=1);

use App\ClientPortal\Actions\Access\IssueClientPortalLinkAction;
use App\ClientPortal\Actions\Access\RevokeClientPortalAccessAction;
use App\ClientPortal\Enums\ClientAccessGrantScope;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\Outreach\ClientPortalLinkInviteNotification;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

covers(IssueClientPortalLinkAction::class);

test('staff can issue a client a standing portal link from the client page', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    Notification::fake();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.clients.access-grants.store', $tenant, [
            'client' => $client->id,
        ]), ['send_invite' => '1'])
        ->assertRedirect(workspaceRoute('workspaces.clients.show', $tenant, ['client' => $client->id]));

    $grant = ClientAccessGrant::query()->where('client_id', $client->id)->firstOrFail();

    expect($grant->scope)->toBe(ClientAccessGrantScope::Client)
        ->and($grant->dossier_id)->toBeNull();

    Notification::assertSentOnDemand(ClientPortalLinkInviteNotification::class);
});

test('issuing a client link shares nothing by itself', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $draft = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Draft,
    ]);

    resolve(IssueClientPortalLinkAction::class)->handle($client, $owner, 30, false);

    // The difference from the per-dossier action, which publishes what it mints
    // a token for. A door is not a decision about what sits behind it.
    expect($draft->fresh()->status)->toBe(DossierStatus::Draft);
});

test('the plaintext link is handed over once and never stored', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.clients.access-grants.store', $tenant, [
            'client' => $client->id,
        ]), ['send_invite' => '0'])
        ->assertSessionHas('access_grant_portal_url');

    $grant = ClientAccessGrant::query()->where('client_id', $client->id)->firstOrFail();

    // Only the hash is persisted.
    expect($grant->token)->not->toContain('http');

    // The page it redirects to shows the link once...
    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.show', $tenant, ['client' => $client->id]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('access_grant_portal_url', fn (?string $url): bool => $url !== null));

    // ...and a refresh does not bring it back, because the query pulls it.
    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.show', $tenant, ['client' => $client->id]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('access_grant_portal_url', null));
});

test('the client page lists the standing link and how much it opens', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);

    $this->actingAs($context['owner'])
        ->get(workspaceRoute('workspaces.clients.show', $context['tenant'], [
            'client' => $context['client']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('portal_links', 1)
            ->where('portal_links.0.is_valid', true)
            // Two shared, one still a draft.
            ->where('portal_links.0.shared_dossier_count', 2)
            ->where('can_grant_portal_access', true));
});

test('a member without the grant permission cannot issue a client link', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $readOnly = workspaceMember($tenant, Role::ReadOnly);

    $this->actingAs($readOnly)
        ->post(workspaceRoute('workspaces.clients.access-grants.store', $tenant, [
            'client' => $client->id,
        ]))
        ->assertForbidden();

    expect(ClientAccessGrant::query()->where('client_id', $client->id)->exists())->toBeFalse();
});

test('revoking a client link returns to the client page, not a dossier', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);

    $this->actingAs($context['owner'])
        ->delete(workspaceRoute('workspaces.access-grants.destroy', $context['tenant'], [
            'grant' => $context['grant']->id,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.clients.show', $context['tenant'], [
            'client' => $context['client']->id,
        ]));

    expect($context['grant']->fresh()->revoked_at)->not->toBeNull();
});

test('a revoked client link stops opening anything', function (): void {
    $context = createPortalClientWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));
    $this->get(route('portal.dossiers.show', ['dossier' => $context['shared']->id]))->assertOk();

    actingInWorkspace($context['tenant']);
    resolve(RevokeClientPortalAccessAction::class)
        ->handle($context['grant'], $context['owner']);

    // Disclosed rather than concealed: the client held a real session, so the
    // ended page explains itself instead of 404ing them.
    $this->get(route('portal.dossiers.show', ['dossier' => $context['shared']->id]))
        ->assertRedirect(route('portal.ended'));
});

test('a client link covering a dossier is visible on that dossier page', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);

    // Without this, staff revoke the dossier's own link, see an empty list, and
    // reasonably conclude the client can no longer get in — while the standing
    // link still opens this very dossier.
    $this->actingAs($context['owner'])
        ->get(workspaceRoute('workspaces.dossiers.show', $context['tenant'], [
            'dossier' => $context['shared']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossier.access_grants', 1)
            ->where('dossier.access_grants.0.id', $context['grant']->id)
            ->where('dossier.access_grants.0.is_client_level', true));
});

test('a client link does not appear on another client dossier', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $otherClient = Client::factory()->create(['tenant_id' => $context['tenant']->id]);
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'client_id' => $otherClient->id,
        'status' => DossierStatus::AwaitingClient,
    ]);

    $this->actingAs($context['owner'])
        ->get(workspaceRoute('workspaces.dossiers.show', $context['tenant'], [
            'dossier' => $otherDossier->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->has('dossier.access_grants', 0));
});
