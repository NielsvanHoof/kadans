import { useState } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import FirmToggleField from '@/features/workspaces/settings/firm-toggle-field';
import { useTranslation } from '@/hooks/use-translation';

type FirmAiSectionProps = {
    ai: App.Tenancy.Data.TenantAiSettingsData;
};

export default function FirmAiSection({ ai }: FirmAiSectionProps) {
    const { t } = useTranslation();
    const [documentOverview, setDocumentOverview] = useState(
        ai.document_overview,
    );
    const [checklistDraft, setChecklistDraft] = useState(ai.checklist_draft);
    const [reminderDraft, setReminderDraft] = useState(ai.reminder_draft);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Assisted features')}</CardTitle>
                <CardDescription>
                    {t(
                        'Turn off features your firm does not want. Everything stays available to do by hand; turning a feature off stops the related client information being sent for analysis.',
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent>
                <FieldGroup className="gap-6">
                    <FirmToggleField
                        name="ai_document_overview_enabled"
                        label={t('Document overview')}
                        description={t(
                            'Automatically summarize scanned documents and classify their contents.',
                        )}
                        isSelected={documentOverview}
                        onChange={setDocumentOverview}
                    />

                    <FirmToggleField
                        name="ai_checklist_draft_enabled"
                        label={t('Checklist suggestions')}
                        description={t(
                            'Suggest questionnaire items while editing firm templates.',
                        )}
                        isSelected={checklistDraft}
                        onChange={setChecklistDraft}
                    />

                    <FirmToggleField
                        name="ai_reminder_draft_enabled"
                        label={t('Reminder drafts')}
                        description={t(
                            'Draft client reminder messages from dossier context.',
                        )}
                        isSelected={reminderDraft}
                        onChange={setReminderDraft}
                    />
                </FieldGroup>
            </CardContent>
        </Card>
    );
}
