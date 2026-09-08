<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use App\Tenancy\Models\TenantSetting;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TenantAiSettingsData extends Data
{
    public function __construct(
        public readonly bool $documentOverview,
        public readonly bool $checklistDraft,
        public readonly bool $reminderDraft,
    ) {}

    public static function fromModel(TenantSetting $settings): self
    {
        return new self(
            documentOverview: $settings->ai_document_overview_enabled,
            checklistDraft: $settings->ai_checklist_draft_enabled,
            reminderDraft: $settings->ai_reminder_draft_enabled,
        );
    }
}
