<?php

declare(strict_types=1);

use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Tenancy\Models\Tenant;
use Laravel\Scout\Builder as ScoutBuilder;

covers(QuestionnaireTemplate::class);

/*
|--------------------------------------------------------------------------
| Scout search window scoping
|--------------------------------------------------------------------------
|
| QuestionnaireTemplate is not a TenantOwnedModel — tenant_id is nullable so
| system templates stay shared — so it carries no TenantScope. Scout's
| DatabaseEngine builds its search from newScoutQuery() when defined and
| newQuery() otherwise, then truncates at scout.key_limit. Without the
| override the window is drawn from every firm's templates before the caller's
| visibility filter runs, so a firm's own templates fall out of its own search
| results as the platform takes on more firms.
|
| The suite runs SCOUT_DRIVER=collection, whose engine builds from query() and
| ignores newScoutQuery() entirely — so an end-to-end search cannot exercise
| this. These tests assert the override itself instead.
|
*/

test('the scout query only reaches system templates and the current firm', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    ['tenant' => $otherTenant] = provisionWorkspace('Rival Bookkeepers');

    actingInWorkspace($tenant);

    $system = QuestionnaireTemplate::factory()->create([
        'tenant_id' => null,
        'name' => 'System KYC',
    ]);

    $own = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Our onboarding pack',
    ]);

    $foreign = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Their onboarding pack',
    ]);

    $reachable = new QuestionnaireTemplate()
        ->newScoutQuery(Mockery::mock(ScoutBuilder::class))
        ->pluck('id')
        ->all();

    expect($reachable)->toContain($system->id)
        ->and($reachable)->toContain($own->id)
        ->and($reachable)->not->toContain($foreign->id);
});

test('the scout query narrows to system templates when no firm is current', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $system = QuestionnaireTemplate::factory()->create([
        'tenant_id' => null,
        'name' => 'System KYC',
    ]);

    $firmOwned = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Our onboarding pack',
    ]);

    // provisionWorkspace() makes the tenant current; drop it so this exercises
    // the console / pre-tenant path, which must narrow rather than widen.
    Tenant::forgetCurrent();

    $reachable = new QuestionnaireTemplate()
        ->newScoutQuery(Mockery::mock(ScoutBuilder::class))
        ->pluck('id')
        ->all();

    expect($reachable)->toContain($system->id)
        ->and($reachable)->not->toContain($firmOwned->id);
});

test('the scout query matches the visibility filter used by route binding', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    ['tenant' => $otherTenant] = provisionWorkspace('Rival Bookkeepers');

    actingInWorkspace($tenant);

    QuestionnaireTemplate::factory()->create(['tenant_id' => null]);
    QuestionnaireTemplate::factory()->create(['tenant_id' => $tenant->id]);
    QuestionnaireTemplate::factory()->create(['tenant_id' => $otherTenant->id]);

    $scoutReachable = new QuestionnaireTemplate()
        ->newScoutQuery(Mockery::mock(ScoutBuilder::class))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $visible = QuestionnaireTemplate::queryVisibleToCurrentTenant()
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($scoutReachable)->toBe($visible);
});
