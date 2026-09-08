<?php

declare(strict_types=1);

namespace App\Tenancy\Enums;

use App\Tenancy\Models\TenantSetting;

enum TenantAiFeature: string
{
    case DocumentOverview = 'document_overview';
    case ChecklistDraft = 'checklist_draft';
    case ReminderDraft = 'reminder_draft';
    case DocumentSearch = 'document_search';

    public function isEnabledOn(TenantSetting $settings): bool
    {
        return match ($this) {
            self::DocumentOverview => $settings->ai_document_overview_enabled,
            self::ChecklistDraft => $settings->ai_checklist_draft_enabled,
            self::ReminderDraft => $settings->ai_reminder_draft_enabled,
            self::DocumentSearch => $settings->ai_document_search_enabled,
        };
    }
}
