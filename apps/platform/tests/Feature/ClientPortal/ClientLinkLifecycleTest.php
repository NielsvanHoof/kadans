<?php

declare(strict_types=1);

use App\ClientPortal\Actions\Access\CreateClientAccessGrantAction;
use App\ClientPortal\Actions\Access\IssueClientPortalLinkAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Actions\DeleteClientAction;
use App\Clients\Models\Client;
use App\Dossiers\Actions\Lifecycle\DeleteDossierAction;
use App\Platform\Actions\DataSubject\AnonymizeDataSubjectAction;
use App\Platform\Actions\DataSubject\GenerateDataSubjectExportAction;
use App\Platform\Actions\DataSubject\LogDataSubjectRequestAction;
use App\Platform\Enums\DataSubjectRequestType;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/*
 * What ends a standing link, and what must never leave one behind.
 *
 * A client-scoped grant carries a null dossier_id, which is precisely the shape
 * that falls through `whereIn('dossier_id', ...)` — the pattern erasure, export,
 * and the archive listener all used before this existed.
 */

test('archiving one dossier does not revoke the client standing link', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    resolve(DeleteDossierAction::class)->handle($context['shared'], $context['owner']);

    // The archived dossier drops out of reach; the link itself keeps working for
    // everything else the firm has shared.
    expect($context['grant']->fresh()->revoked_at)->toBeNull();

    $this->get(route('portal.exchange', $context['plainTextToken']));
    $this->get(route('portal.dossiers.show', ['dossier' => $context['shared']->id]))
        ->assertNotFound();
    $this->get(route('portal.dossiers.show', ['dossier' => $context['secondShared']->id]))
        ->assertOk();
});

test('archiving the client revokes the standing link outright', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    resolve(DeleteClientAction::class)->handle($context['client'], $context['owner']);

    // Not merely unreachable: an unrevoked link would come back to life the
    // moment someone restored the client, still inside its original expiry.
    expect($context['grant']->fresh()->revoked_at)->not->toBeNull();
});

test('erasure removes client-scoped grants, not just dossier ones', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $dossierGrant = resolve(CreateClientAccessGrantAction::class)
        ->handle($context['shared'], $context['owner'], 7);

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($context['client'], $context['owner'], DataSubjectRequestType::Erasure);

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $context['owner']);

    // Leaving the client-scoped row behind would mean a live portal link into
    // the records of someone who asked to be forgotten.
    expect(ClientAccessGrant::query()->whereKey($context['grant']->id)->exists())->toBeFalse()
        ->and(ClientAccessGrant::query()->whereKey($dossierGrant['grant']->id)->exists())->toBeFalse();
});

test('the subject export accounts for the standing link', function (): void {
    Storage::fake((string) config('exports.disk'));

    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($context['client'], $context['owner'], DataSubjectRequestType::Export);

    resolve(GenerateDataSubjectExportAction::class)->handle($request, dataSubjectExportStaleCutoff());

    $request->refresh();

    $zip = new ZipArchive();
    $zip->open(Storage::disk((string) config('exports.disk'))->path((string) $request->path));
    $manifest = json_decode((string) $zip->getFromName('data.json'), associative: true);

    // The subject's own record of who could see their documents has to include
    // the link that opens all of them.
    expect($manifest['portal_access'])->toHaveCount(1)
        ->and($manifest['portal_access'][0]['scope'])->toBe('client')
        ->and($manifest['portal_access'][0]['dossier_id'])->toBeNull();
});

test('a client link uses the client TTL, and cannot exceed its own ceiling', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $result = resolve(IssueClientPortalLinkAction::class)->handle($client, $owner, null, false);

    expect($result['grant']->expires_at->isSameDay(
        now()->addDays(Config::integer('portal.client_grant_ttl_days')),
    ))->toBeTrue();

    // Its own ceiling, lower than a dossier link's 90: one standing link opens
    // the client's whole book, so the window it is worth stealing in is shorter.
    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.clients.access-grants.store', $tenant, [
            'client' => $client->id,
        ]), ['expires_in_days' => Config::integer('portal.client_grant_max_ttl_days') + 1])
        ->assertSessionHasErrors('expires_in_days');
});
