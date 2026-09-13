<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Reporting\Queries\Dashboard\FetchDashboardTrendQuery;
use Database\Seeders\RolesAndPermissionsSeeder;

covers(FetchDashboardTrendQuery::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function answeredRequest(object $tenant, object $dossier, mixed $answeredAt): DocumentRequest
{
    return DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Submitted,
        'answered_at' => $answeredAt,
    ]);
}

test('the series always spans eight weeks, quiet ones included', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $points = resolve(FetchDashboardTrendQuery::class)->handle($tenant);

    // A gap on a date axis reads as "no data", which is a different claim from
    // "nothing happened" — so every bucket is emitted, even at zero.
    expect($points)->toHaveCount(8)
        ->and(collect($points)->pluck('documentsReceived')->unique()->all())->toBe([0]);
});

test('answered requests land in the week they were answered', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    answeredRequest($tenant, $dossier, now());
    answeredRequest($tenant, $dossier, now());
    answeredRequest($tenant, $dossier, now()->subWeeks(2));

    $points = collect(resolve(FetchDashboardTrendQuery::class)->handle($tenant))
        ->keyBy('weekStart');

    $thisWeek = today()->startOfWeek()->toDateString();
    $twoWeeksAgo = today()->startOfWeek()->subWeeks(2)->toDateString();

    expect($points[$thisWeek]->documentsReceived)->toBe(2)
        ->and($points[$twoWeeksAgo]->documentsReceived)->toBe(1);
});

test('unanswered requests and older history stay out of the series', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
        'answered_at' => null,
    ]);
    // Before the window opens.
    answeredRequest($tenant, $dossier, now()->subWeeks(20));

    $total = collect(resolve(FetchDashboardTrendQuery::class)->handle($tenant))
        ->sum('documentsReceived');

    expect($total)->toBe(0);
});

test('the series is scoped to one firm', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    ['tenant' => $foreignTenant] = provisionWorkspace('Other firm');
    actingInWorkspace($foreignTenant);
    $foreignClient = Client::factory()->create(['tenant_id' => $foreignTenant->id]);
    $foreignDossier = Dossier::factory()->create([
        'tenant_id' => $foreignTenant->id,
        'client_id' => $foreignClient->id,
    ]);
    answeredRequest($foreignTenant, $foreignDossier, now());

    actingInWorkspace($tenant);

    $total = collect(resolve(FetchDashboardTrendQuery::class)->handle($tenant))
        ->sum('documentsReceived');

    expect($total)->toBe(0);
});
