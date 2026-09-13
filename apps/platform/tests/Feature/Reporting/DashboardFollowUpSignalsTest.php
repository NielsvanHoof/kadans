<?php

declare(strict_types=1);

use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Reporting\Queries\Dashboard\FetchDashboardFollowUpSignalsQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Config;

covers(FetchDashboardFollowUpSignalsQuery::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A dossier with one outstanding request, so it qualifies for the quiet-client
 * signal without every test restating the request.
 *
 * @param  array<string, mixed>  $attributes
 */
function followUpDossier(object $tenant, object $client, array $attributes = []): Dossier
{
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        ...$attributes,
    ]);

    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    return $dossier;
}

function followUpSignals(object $tenant): object
{
    return resolve(FetchDashboardFollowUpSignalsQuery::class)->handle($tenant);
}

describe('invited but never opened', function (): void {
    test('a client who has not opened the link is flagged', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        followUpDossier($tenant, $client, [
            'title' => 'Never touched',
            'last_client_message_sent_at' => now()->subDays(9),
            'last_client_opened_at' => null,
        ]);

        $signals = followUpSignals($tenant);

        expect($signals->neverOpened)->toHaveCount(1)
            ->and($signals->neverOpened[0]->title)->toBe('Never touched')
            ->and($signals->neverOpened[0]->days)->toBe(9);
    });

    test('a client who has opened the link is not flagged', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        followUpDossier($tenant, $client, [
            'last_client_message_sent_at' => now()->subDays(9),
            'last_client_opened_at' => now()->subDays(8),
        ]);

        expect(followUpSignals($tenant)->neverOpened)->toBeEmpty();
    });

    test('a dossier nobody has been invited to is not flagged', function (): void {
        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        followUpDossier($tenant, $client, [
            'last_client_message_sent_at' => null,
            'last_client_opened_at' => null,
        ]);

        expect(followUpSignals($tenant)->neverOpened)->toBeEmpty();
    });

    test('the grace period is respected', function (int $daysAgo, int $expected): void {
        Config::set('portal.follow_up.never_opened_days', 3);

        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        followUpDossier($tenant, $client, [
            'last_client_message_sent_at' => now()->subDays($daysAgo),
            'last_client_opened_at' => null,
        ]);

        expect(followUpSignals($tenant)->neverOpened)->toHaveCount($expected);
    })->with([
        'inside the grace period' => [2, 0],
        'on the threshold' => [3, 1],
        'past the threshold' => [4, 1],
    ]);
});

describe('gone quiet', function (): void {
    test('a client silent past the threshold is flagged', function (): void {
        Config::set('portal.follow_up.stale_activity_days', 7);

        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        followUpDossier($tenant, $client, [
            'title' => 'Went quiet',
            'last_client_opened_at' => now()->subDays(10),
        ]);

        $signals = followUpSignals($tenant);

        expect($signals->goneQuiet)->toHaveCount(1)
            ->and($signals->goneQuiet[0]->title)->toBe('Went quiet');
    });

    test('a dossier with nothing outstanding is not chased', function (): void {
        Config::set('portal.follow_up.stale_activity_days', 7);

        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        // No outstanding request, so there is nothing to nudge about.
        Dossier::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'last_client_opened_at' => now()->subDays(30),
        ]);

        expect(followUpSignals($tenant)->goneQuiet)->toBeEmpty();
    });

    test('a completed dossier stops generating signals', function (): void {
        Config::set('portal.follow_up.stale_activity_days', 7);

        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        followUpDossier($tenant, $client, [
            'status' => DossierStatus::Completed,
            'last_client_opened_at' => now()->subDays(30),
        ]);

        expect(followUpSignals($tenant)->goneQuiet)->toBeEmpty();
    });
});

describe('expiring portal links', function (): void {
    test('a link lapsing inside the window is flagged', function (): void {
        Config::set('portal.follow_up.expiring_link_days', 7);

        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $dossier = followUpDossier($tenant, $client, ['title' => 'Expiring soon']);

        ClientAccessGrant::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'expires_at' => now()->addDays(2),
        ]);

        $signals = followUpSignals($tenant);

        expect($signals->expiringLinks)->toHaveCount(1)
            ->and($signals->expiringLinks[0]->title)->toBe('Expiring soon')
            ->and($signals->expiringLinks[0]->days)->toBe(2);
    });

    test('revoked and already lapsed links are ignored', function (): void {
        Config::set('portal.follow_up.expiring_link_days', 7);

        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $dossier = followUpDossier($tenant, $client);

        ClientAccessGrant::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'expires_at' => now()->addDays(2),
            'revoked_at' => now()->subDay(),
        ]);
        ClientAccessGrant::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'expires_at' => now()->subDay(),
        ]);
        // Beyond the window.
        ClientAccessGrant::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'expires_at' => now()->addDays(30),
        ]);

        expect(followUpSignals($tenant)->expiringLinks)->toBeEmpty();
    });
});

test('signals never cross a firm boundary', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    ['tenant' => $foreignTenant] = provisionWorkspace('Other firm');
    actingInWorkspace($foreignTenant);
    $foreignClient = Client::factory()->create(['tenant_id' => $foreignTenant->id]);
    $foreignDossier = followUpDossier($foreignTenant, $foreignClient, [
        'last_client_message_sent_at' => now()->subDays(9),
        'last_client_opened_at' => null,
    ]);
    ClientAccessGrant::factory()->create([
        'tenant_id' => $foreignTenant->id,
        'dossier_id' => $foreignDossier->id,
        'expires_at' => now()->addDays(2),
    ]);

    actingInWorkspace($tenant);

    expect(followUpSignals($tenant)->totalCount)->toBe(0);
});
