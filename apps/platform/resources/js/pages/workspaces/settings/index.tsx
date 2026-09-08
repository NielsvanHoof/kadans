import { Head, router, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceSettingsController from '@/actions/App/Tenancy/Http/Controllers/Settings/WorkspaceSettingsController';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import Heading from '@/components/primitives/heading';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as settingsIndex } from '@/routes/workspaces/settings';

type WorkspaceSettingsPageProps =
    App.Tenancy.Data.Settings.WorkspaceSettingsPageData;

/**
 * Owner-only firm settings. AI toggles opt out of Bedrock-backed helpers
 * while leaving manual workflows available.
 */
export default function WorkspaceSettingsIndex({
    settings,
}: WorkspaceSettingsPageProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [documentOverview, setDocumentOverview] = useState(
        settings.ai.document_overview,
    );
    const [checklistDraft, setChecklistDraft] = useState(
        settings.ai.checklist_draft,
    );
    const [reminderDraft, setReminderDraft] = useState(
        settings.ai.reminder_draft,
    );
    const [documentSearch, setDocumentSearch] = useState(
        settings.ai.document_search,
    );
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Firm settings'),
                href: settingsIndex(currentWorkspace),
            },
        ],
    });

    function saveSettings() {
        setProcessing(true);
        setErrors({});

        router.patch(
            WorkspaceSettingsController.update.url({
                tenant: currentWorkspace,
            }),
            {
                ai_document_overview_enabled: documentOverview,
                ai_checklist_draft_enabled: checklistDraft,
                ai_reminder_draft_enabled: reminderDraft,
                ai_document_search_enabled: documentSearch,
            },
            {
                preserveScroll: true,
                onError: (pageErrors) => {
                    setErrors(pageErrors as Record<string, string>);
                },
                onFinish: () => {
                    setProcessing(false);
                },
            },
        );
    }

    return (
        <>
            <Head title={t('Firm settings')} />

            <div className="flex flex-col gap-6">
                <Heading
                    level={2}
                    className="mb-0"
                    title={t('Firm settings')}
                    description={t('Configure firm-wide settings.')}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>{t('AI features')}</CardTitle>
                        <CardDescription>
                            {t(
                                'Turn off features your firm does not want. Inference stays on AWS Bedrock in-region; disabling a feature stops sending the related data to a model.',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <FieldGroup className="gap-6">
                            <Field
                                orientation="horizontal"
                                className="items-start gap-3"
                            >
                                <Checkbox
                                    id="ai-document-overview"
                                    isSelected={documentOverview}
                                    onChange={setDocumentOverview}
                                />
                                <div className="grid gap-1">
                                    <FieldLabel htmlFor="ai-document-overview">
                                        {t('Document overview')}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'Automatically summarize scanned documents and classify their contents.',
                                        )}
                                    </FieldDescription>
                                </div>
                            </Field>

                            <Field
                                orientation="horizontal"
                                className="items-start gap-3"
                            >
                                <Checkbox
                                    id="ai-checklist-draft"
                                    isSelected={checklistDraft}
                                    onChange={setChecklistDraft}
                                />
                                <div className="grid gap-1">
                                    <FieldLabel htmlFor="ai-checklist-draft">
                                        {t('Checklist suggestions')}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'Suggest questionnaire items while editing firm templates.',
                                        )}
                                    </FieldDescription>
                                </div>
                            </Field>

                            <Field
                                orientation="horizontal"
                                className="items-start gap-3"
                            >
                                <Checkbox
                                    id="ai-reminder-draft"
                                    isSelected={reminderDraft}
                                    onChange={setReminderDraft}
                                />
                                <div className="grid gap-1">
                                    <FieldLabel htmlFor="ai-reminder-draft">
                                        {t('Reminder drafts')}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'Draft client reminder messages from dossier context.',
                                        )}
                                    </FieldDescription>
                                </div>
                            </Field>

                            <Field
                                orientation="horizontal"
                                className="items-start gap-3"
                            >
                                <Checkbox
                                    id="ai-document-search"
                                    isSelected={documentSearch}
                                    onChange={setDocumentSearch}
                                />
                                <div className="grid gap-1">
                                    <FieldLabel htmlFor="ai-document-search">
                                        {t('Document search')}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'Search and ask questions about uploaded documents in this workspace.',
                                        )}
                                    </FieldDescription>
                                </div>
                            </Field>

                            <InputError
                                message={errors.ai_document_overview_enabled}
                            />
                            <InputError
                                message={errors.ai_checklist_draft_enabled}
                            />
                            <InputError
                                message={errors.ai_reminder_draft_enabled}
                            />
                            <InputError
                                message={errors.ai_document_search_enabled}
                            />

                            <Field orientation="horizontal">
                                <Button
                                    type="button"
                                    onClick={saveSettings}
                                    isDisabled={processing}
                                >
                                    {processing ? <Spinner /> : null}
                                    {processing
                                        ? t('Saving…')
                                        : t('Save settings')}
                                </Button>
                            </Field>
                        </FieldGroup>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
