<?php

declare(strict_types=1);

use App\Tenancy\Data\Settings\TenantAiSettingsData;
use App\Tenancy\Models\TenantSetting;

covers(TenantAiSettingsData::class);

test('a firm with no settings row yet gets every ai feature enabled', function (): void {
    $settings = TenantAiSettingsData::fromModel(null);

    expect($settings->documentOverview)->toBeTrue()
        ->and($settings->checklistDraft)->toBeTrue()
        ->and($settings->reminderDraft)->toBeTrue()
        ->and($settings->documentSearch)->toBeTrue();
});

/*
 * The mapper reads `$settings->flag ?? true`, so a stored `false` must not fall
 * through to the enabled default — that would silently re-enable an AI feature
 * a firm had switched off.
 */
test('a stored false is not coerced to the enabled default', function (): void {
    $settings = TenantAiSettingsData::fromModel(new TenantSetting([
        'ai_document_overview_enabled' => false,
        'ai_checklist_draft_enabled' => false,
        'ai_reminder_draft_enabled' => false,
        'ai_document_search_enabled' => false,
    ]));

    expect($settings->documentOverview)->toBeFalse()
        ->and($settings->checklistDraft)->toBeFalse()
        ->and($settings->reminderDraft)->toBeFalse()
        ->and($settings->documentSearch)->toBeFalse();
});

test('an attribute missing from the row falls back to enabled', function (): void {
    $settings = TenantAiSettingsData::fromModel(new TenantSetting([
        'ai_document_overview_enabled' => false,
    ]));

    expect($settings->documentOverview)->toBeFalse()
        ->and($settings->checklistDraft)->toBeTrue()
        ->and($settings->reminderDraft)->toBeTrue()
        ->and($settings->documentSearch)->toBeTrue();
});
