<?php

declare(strict_types=1);

use App\Questionnaires\Ai\ChecklistDraftAgent;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Tenancy\Enums\Role;
use Database\Seeders\SystemQuestionnaireTemplateSeeder;
use Laravel\Ai\Prompts\AgentPrompt;

test('owner can generate checklist suggestions for a firm template', function (): void {
    ChecklistDraftAgent::fake([[
        'items' => [
            ['title' => 'Bank statement', 'instructions' => 'Last 3 months.', 'type' => 'file'],
            ['title' => 'Confirm turnover', 'instructions' => 'Yes or no.', 'type' => 'boolean'],
            ['title' => 'Registered address', 'instructions' => 'Full address.', 'type' => 'text'],
        ],
    ]]);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $template = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Custom pack',
    ]);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.templates.draft-items.store', $tenant, ['template' => $template]), [
            'description' => 'Onboarding a new freelance client for their first tax return',
        ])
        ->assertOk()
        ->assertJson([
            'items' => [
                ['title' => 'Bank statement', 'instructions' => 'Last 3 months.', 'type' => 'file'],
                ['title' => 'Confirm turnover', 'instructions' => 'Yes or no.', 'type' => 'boolean'],
                ['title' => 'Registered address', 'instructions' => 'Full address.', 'type' => 'text'],
            ],
        ]);

    ChecklistDraftAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('freelance client'),
    );
});

test('an invalid type hint is normalized to file', function (): void {
    ChecklistDraftAgent::fake([[
        'items' => [
            ['title' => 'Bank statement', 'instructions' => 'Last 3 months.', 'type' => 'not-a-real-type'],
        ],
    ]]);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $template = QuestionnaireTemplate::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.templates.draft-items.store', $tenant, ['template' => $template]), [
            'description' => 'Anything',
        ])
        ->assertOk()
        ->assertJson(['items' => [
            ['title' => 'Bank statement', 'instructions' => 'Last 3 months.', 'type' => 'file'],
        ]]);
});

test('system templates cannot be used to generate suggestions', function (): void {
    ChecklistDraftAgent::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $this->seed(SystemQuestionnaireTemplateSeeder::class);
    $system = QuestionnaireTemplate::query()->whereNull('tenant_id')->firstOrFail();

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.templates.draft-items.store', $tenant, ['template' => $system]), [
            'description' => 'Anything',
        ])
        ->assertForbidden();

    ChecklistDraftAgent::assertNeverPrompted();
});

test('firm templates are isolated between tenants for suggestions', function (): void {
    ChecklistDraftAgent::fake();

    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Tenant A');
    ['owner' => $ownerB, 'tenant' => $tenantB] = provisionWorkspace('Tenant B');

    actingInWorkspace($tenantA);
    $templateA = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Secret A',
    ]);

    $this->actingAs($ownerB)
        ->postJson(workspaceRoute('workspaces.templates.draft-items.store', $tenantB, ['template' => $templateA]), [
            'description' => 'Anything',
        ])
        ->assertNotFound();

    ChecklistDraftAgent::assertNeverPrompted();
});

test('read only members cannot generate checklist suggestions', function (): void {
    ChecklistDraftAgent::fake();

    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $template = QuestionnaireTemplate::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($reader)
        ->postJson(workspaceRoute('workspaces.templates.draft-items.store', $tenant, ['template' => $template]), [
            'description' => 'Anything',
        ])
        ->assertForbidden();

    ChecklistDraftAgent::assertNeverPrompted();
});

test('checklist draft endpoint is rate limited', function (): void {
    ChecklistDraftAgent::fake([['items' => [
        ['title' => 'Bank statement', 'instructions' => 'Last 3 months.', 'type' => 'file'],
    ]]]);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $template = QuestionnaireTemplate::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($owner);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson(workspaceRoute('workspaces.templates.draft-items.store', $tenant, ['template' => $template]), [
            'description' => 'Anything',
        ])->assertOk();
    }

    $this->postJson(workspaceRoute('workspaces.templates.draft-items.store', $tenant, ['template' => $template]), [
        'description' => 'Anything',
    ])->assertStatus(429);
});
