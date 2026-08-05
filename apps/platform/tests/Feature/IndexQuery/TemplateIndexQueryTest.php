<?php

declare(strict_types=1);

use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use App\Questionnaires\Models\QuestionnaireTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemQuestionnaireTemplateSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('templates index paginates system and firm buckets independently', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    // Enough firm templates to require a second firm_page.
    QuestionnaireTemplate::factory()->count(16)->create([
        'tenant_id' => $tenant->id,
        'category' => QuestionnaireTemplateCategory::Custom,
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.templates.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('questionnaires/index')
            ->where('system_templates.total', 4)
            ->has('firm_templates.data', 15)
            ->where('firm_templates.total', 16)
            ->where('firm_templates.current_page', 1)
            ->has('indexQuery'));

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.templates.index', $tenant).'?firm_page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('firm_templates.data', 1)
            ->where('firm_templates.current_page', 2)
            // System bucket stays on page 1 when only firm_page changes.
            ->where('system_templates.current_page', 1));
});

test('templates index shared category filter applies to both buckets', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Firm KYC',
        'category' => QuestionnaireTemplateCategory::Kyc,
    ]);
    QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Firm Custom',
        'category' => QuestionnaireTemplateCategory::Custom,
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.templates.index', $tenant).'?filter[category]=kyc')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('firm_templates.total', 1)
            ->where('firm_templates.data.0.name', 'Firm KYC')
            ->where('system_templates.data', fn ($templates) => collect($templates)->every(
                fn (array $template): bool => $template['category'] === 'kyc',
            )));
});
