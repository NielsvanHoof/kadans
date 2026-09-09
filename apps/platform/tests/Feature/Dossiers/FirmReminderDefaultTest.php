<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\TenantSetting;

/**
 * Absent and empty are different answers.
 *
 * Omitting the field means the caller expressed no preference, so the firm's
 * house rhythm applies. Sending it empty means "no reminders on this dossier",
 * and filling that in would switch reminders on for a dossier whose author
 * deliberately turned them off.
 */
function createDossierForReminderDefaultTest(array $payload = []): void
{
    ['tenant' => $tenant] = test()->workspace;

    test()->post(workspaceRoute('workspaces.dossiers.store', $tenant), [
        'client_id' => test()->client->id,
        'title' => 'Payroll dossier',
        ...$payload,
    ])->assertRedirect()->assertSessionHasNoErrors();
}

beforeEach(function (): void {
    $this->workspace = provisionWorkspace();
    $this->actingAs($this->workspace['owner']);
    $this->client = Client::factory()->create(['tenant_id' => $this->workspace['tenant']->id]);
});

function setFirmReminderDefaultForTest(?int $days): void
{
    TenantSetting::query()
        ->where('tenant_id', test()->workspace['tenant']->id)
        ->update(['default_reminder_interval_days' => $days]);
}

test('a new dossier picks up the firm default when no interval is given', function (): void {
    setFirmReminderDefaultForTest(7);

    createDossierForReminderDefaultTest();

    expect(Dossier::query()->sole()->reminder_interval_days)->toBe(7);
});

test('an explicitly empty interval still means no reminders', function (): void {
    setFirmReminderDefaultForTest(7);

    createDossierForReminderDefaultTest(['reminder_interval_days' => '']);

    expect(Dossier::query()->sole()->reminder_interval_days)->toBeNull();
});

test('an interval chosen on the dossier wins over the firm default', function (): void {
    setFirmReminderDefaultForTest(7);

    createDossierForReminderDefaultTest(['reminder_interval_days' => 3]);

    expect(Dossier::query()->sole()->reminder_interval_days)->toBe(3);
});

test('a firm that has set no default leaves the interval empty', function (): void {
    setFirmReminderDefaultForTest(null);

    createDossierForReminderDefaultTest();

    expect(Dossier::query()->sole()->reminder_interval_days)->toBeNull();
});

test('editing a dossier does not switch reminders back on', function (): void {
    setFirmReminderDefaultForTest(7);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $this->workspace['tenant']->id,
        'client_id' => $this->client->id,
        'reminder_interval_days' => null,
    ]);

    $this->patch(
        workspaceRoute('workspaces.dossiers.update', $this->workspace['tenant'], ['dossier' => $dossier]),
        ['title' => $dossier->title, 'client_id' => $this->client->id],
    )->assertSessionHasNoErrors();

    expect($dossier->fresh()->reminder_interval_days)->toBeNull();
});

test('an interval outside the allowed band is refused', function (int $days): void {
    ['tenant' => $tenant] = $this->workspace;

    $this->post(workspaceRoute('workspaces.dossiers.store', $tenant), [
        'client_id' => $this->client->id,
        'title' => 'Payroll dossier',
        'reminder_interval_days' => $days,
    ])->assertSessionHasErrors('reminder_interval_days');

    expect(Dossier::query()->count())->toBe(0);
})->with(['too short' => 0, 'too long' => 31]);
