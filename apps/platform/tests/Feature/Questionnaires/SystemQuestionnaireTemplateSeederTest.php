<?php

declare(strict_types=1);

use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use Database\Seeders\SystemQuestionnaireTemplateSeeder;

covers(SystemQuestionnaireTemplateSeeder::class);

test('system questionnaire template seeder creates missing templates once', function (): void {
    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $template = QuestionnaireTemplate::query()
        ->whereNull('tenant_id')
        ->where('category', QuestionnaireTemplateCategory::Kyc->value)
        ->firstOrFail();

    $itemIds = $template->items()->orderBy('sort_order')->pluck('id')->all();
    $fieldKeys = $template->items()->orderBy('sort_order')->pluck('field_key')->all();
    $description = $template->description;
    $templateCount = QuestionnaireTemplate::query()->whereNull('tenant_id')->count();
    $itemCount = QuestionnaireTemplateItem::query()->count();

    expect($itemIds)->not->toBeEmpty()
        ->and($fieldKeys)->each->not->toBeNull();

    // Mutate so a destructive re-seed would be visible.
    $template->forceFill(['description' => 'Edited by ops'])->save();
    $template->items()->firstOrFail()->forceFill(['title' => 'Edited item'])->save();

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $template->refresh();

    expect($template->description)->toBe('Edited by ops')
        ->and($template->items()->orderBy('sort_order')->pluck('id')->all())->toBe($itemIds)
        ->and($template->items()->orderBy('sort_order')->pluck('field_key')->all())->toBe($fieldKeys)
        ->and($template->items()->firstOrFail()->title)->toBe('Edited item')
        ->and(QuestionnaireTemplate::query()->whereNull('tenant_id')->count())->toBe($templateCount)
        ->and(QuestionnaireTemplateItem::query()->count())->toBe($itemCount);
});

test('system questionnaire template seeder recreates a missing system template', function (): void {
    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $kyc = QuestionnaireTemplate::query()
        ->whereNull('tenant_id')
        ->where('name', 'KYC onboarding')
        ->firstOrFail();

    $kyc->items()->delete();
    $kyc->delete();

    expect(
        QuestionnaireTemplate::query()
            ->whereNull('tenant_id')
            ->where('name', 'KYC onboarding')
            ->exists(),
    )->toBeFalse();

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $restored = QuestionnaireTemplate::query()
        ->whereNull('tenant_id')
        ->where('name', 'KYC onboarding')
        ->firstOrFail();

    expect($restored->items()->count())->toBeGreaterThan(0);
});
