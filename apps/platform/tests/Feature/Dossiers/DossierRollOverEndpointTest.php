<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;

/**
 * @return array{tenant: Tenant, owner: User, dossier: Dossier}
 */
function rollOverEndpointContext(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Completed,
        'reference' => 'JR-2025-001',
        'period_year' => 2025,
        'period_label' => 'Jaarrekening 2025',
    ]);

    DocumentRequest::factory()->create([
        'dossier_id' => $dossier->getKey(),
        'type' => QuestionnaireItemType::Text,
        'answer_text' => 'BV',
    ]);

    return ['tenant' => $tenant, 'owner' => $owner, 'dossier' => $dossier];
}

function rollOverPayload(array $overrides = []): array
{
    return [
        'title' => 'Jaarrekening 2026',
        'reference' => 'JR-2026-001',
        'carry_answers' => true,
        ...$overrides,
    ];
}

test('an owner can roll a completed dossier over and lands on the new one', function (): void {
    $context = rollOverEndpointContext();

    $response = $this->actingAs($context['owner'])->post(
        workspaceRoute('workspaces.dossiers.roll-over', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]),
        rollOverPayload(),
    );

    $rolledOver = Dossier::query()
        ->where('rolled_from_dossier_id', $context['dossier']->getKey())
        ->sole();

    $response->assertRedirect(workspaceRoute(
        'workspaces.dossiers.show',
        $context['tenant'],
        ['dossier' => $rolledOver],
    ));

    expect($rolledOver->period_year)->toBe(2026)
        ->and($rolledOver->documentRequests()->sole()->answer_text)->toBe('BV');
});

test('a read-only member cannot roll a dossier over', function (): void {
    $context = rollOverEndpointContext();
    $reader = workspaceMember($context['tenant'], Role::ReadOnly);

    $this->actingAs($reader)->post(
        workspaceRoute('workspaces.dossiers.roll-over', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]),
        rollOverPayload(),
    )->assertForbidden();

    expect(Dossier::query()->where('rolled_from_dossier_id', $context['dossier']->getKey())->count())
        ->toBe(0);
});

test('the roll-over payload is validated', function (array $payload, string $invalidField): void {
    $context = rollOverEndpointContext();

    $this->actingAs($context['owner'])->post(
        workspaceRoute('workspaces.dossiers.roll-over', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]),
        $payload,
    )->assertInvalid($invalidField);
})->with([
    'missing title' => [['reference' => 'JR-2026-001', 'carry_answers' => true], 'title'],
    'missing reference' => [['title' => 'Jaarrekening 2026', 'carry_answers' => true], 'reference'],
    'reference already used in this firm' => [
        ['title' => 'Jaarrekening 2026', 'reference' => 'JR-2025-001', 'carry_answers' => true],
        'reference',
    ],
    'missing carry choice' => [['title' => 'Jaarrekening 2026', 'reference' => 'JR-2026-001'], 'carry_answers'],
    'period year out of range' => [
        ['title' => 'X', 'reference' => 'JR-2026-001', 'carry_answers' => true, 'period_year' => 1500],
        'period_year',
    ],
]);

/*
 * The dialog offers a bounded year select, so these bounds are only reachable
 * by posting directly. What is asserted here is the message, not the rule: an
 * unmapped field falls back to the raw column name, which produced the Dutch
 * "period year moet minimaal 1900 zijn" for a field the UI calls "Periode".
 */
test('validation messages name fields the way the interface does', function (): void {
    $context = rollOverEndpointContext();

    $response = $this->actingAs($context['owner'])->post(
        workspaceRoute('workspaces.dossiers.roll-over', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]),
        rollOverPayload(['period_year' => 1500, 'reference' => '']),
    );

    $errors = $response->assertInvalid(['period_year', 'reference'])
        ->getSession()
        ->get('errors')
        ->getBag('default');

    expect($errors->first('period_year'))->toContain('Period')
        ->and($errors->first('period_year'))->not->toContain('period year')
        ->and($errors->first('reference'))->toContain('Reference')
        ->and($errors->first('reference'))->not->toContain('optional');
});

// 404 rather than 403 on purpose: a non-member never gets far enough to be
// told the workspace exists, so the tenant middleware refuses the route before
// the policy is consulted.
test('a member of another firm cannot reach the roll-over route', function (): void {
    $context = rollOverEndpointContext();

    ['owner' => $otherOwner, 'tenant' => $otherTenant] = provisionWorkspace('Other Firm');
    actingInWorkspace($context['tenant']);

    $this->actingAs($otherOwner)->post(
        workspaceRoute('workspaces.dossiers.roll-over', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]),
        rollOverPayload(),
    )->assertNotFound();
});
