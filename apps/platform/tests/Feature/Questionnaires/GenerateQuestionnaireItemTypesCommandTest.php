<?php

declare(strict_types=1);

use App\Questionnaires\Console\Commands\Templates\GenerateQuestionnaireItemTypesCommand;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

covers(GenerateQuestionnaireItemTypesCommand::class);

test('questionnaire item type TypeScript stays in sync with the PHP enum', function (): void {
    $path = resource_path('js/features/document-requests/item-types/generated.ts');

    expect(File::exists($path))->toBeTrue()
        ->and(Artisan::call('questionnaire:generate-item-types', ['--check' => true]))
        ->toBe(0);

    $contents = File::get($path);

    foreach (QuestionnaireItemType::values() as $value) {
        expect($contents)->toContain("'{$value}'");
    }

    expect($contents)
        ->toContain("BOOLEAN_YES_OPTION_ID = '".QuestionnaireItemType::BooleanYesOptionId."'")
        ->toContain("BOOLEAN_NO_OPTION_ID = '".QuestionnaireItemType::BooleanNoOptionId."'");
});

test('questionnaire item type capabilities in TypeScript match the PHP enum', function (): void {
    $contents = File::get(resource_path('js/features/document-requests/item-types/generated.ts'));

    foreach (QuestionnaireItemType::cases() as $case) {
        $blockPattern = '/'.preg_quote($case->value, '/').': \{[^}]+\}/s';
        expect($contents)->toMatch($blockPattern);

        preg_match($blockPattern, $contents, $matches);
        $block = $matches[0];

        expect($block)
            ->toContain('storesAnswerText: '.($case->storesAnswerText() ? 'true' : 'false'))
            ->toContain('storesAnswerOptionIds: '.($case->storesAnswerOptionIds() ? 'true' : 'false'))
            ->toContain('canDriveVisibility: '.($case->canDriveVisibility() ? 'true' : 'false'));
    }
});
