import { Form } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceSettingsController from '@/actions/App/Tenancy/Http/Controllers/Settings/WorkspaceSettingsController';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import FirmAiSection from '@/features/workspaces/settings/firm-ai-section';
import FirmIdentitySection from '@/features/workspaces/settings/firm-identity-section';
import FirmPortalSection from '@/features/workspaces/settings/firm-portal-section';
import FirmReminderSection from '@/features/workspaces/settings/firm-reminder-section';
import FirmRetentionSection from '@/features/workspaces/settings/firm-retention-section';
import FirmSecuritySection from '@/features/workspaces/settings/firm-security-section';
import {
    firstTabWithError,
    INACTIVE_PANEL_CLASS,
    tabHasError,
    type SettingsTab,
} from '@/features/workspaces/settings/settings-tabs';
import type { Firm } from '@/features/workspaces/types';
import { useTranslation } from '@/hooks/use-translation';

const TAB_ORDER = [
    'details',
    'security',
    'portal',
    'reminders',
    'retention',
    'assisted',
] as const satisfies readonly SettingsTab[];

type FirmSettingsTabsProps = {
    firm: Firm;
    settings: App.Tenancy.Data.Settings.TenantSettingsData;
};

/**
 * The firm record and the firm's policy are two different writes, so this
 * renders two forms.
 *
 * The tab strip lives inside the policy form because it needs that form's
 * errors to flag which panel failed. The firm details panel has to stay
 * outside it: a form nested inside another form is invalid, and the inner one
 * would never submit. Both are still inside Tabs, which shares state by
 * context rather than by nesting, and DOM order keeps the strip on top.
 */
export default function FirmSettingsTabs({
    firm,
    settings,
}: FirmSettingsTabsProps) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<SettingsTab>('details');

    const tabLabels: Record<SettingsTab, string> = {
        details: t('Details'),
        security: t('Signing in'),
        portal: t('Client portal'),
        reminders: t('Reminders'),
        retention: t('Retention'),
        assisted: t('Assisted features'),
    };

    return (
        <Tabs
            selectedKey={tab}
            onSelectionChange={(key) => setTab(key as SettingsTab)}
            className="gap-6"
        >
            <Form
                {...WorkspaceSettingsController.update.form({ tenant: firm })}
                options={{ preserveScroll: true }}
                // The policy tabs share one save button, so an error on a panel
                // that is not on screen would otherwise read as a button that
                // did nothing.
                onError={(formErrors) => {
                    const failed = firstTabWithError(
                        formErrors as Record<string, string>,
                    );

                    if (failed !== null) {
                        setTab(failed);
                    }
                }}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <TabsList className="h-auto w-full flex-wrap justify-start">
                            {TAB_ORDER.map((id) => (
                                <TabsTrigger id={id} key={id}>
                                    {tabLabels[id]}
                                    {id !== 'details' &&
                                    tabHasError(id, errors) ? (
                                        <>
                                            <span
                                                aria-hidden="true"
                                                className="bg-destructive ml-1 size-1.5 rounded-full"
                                            />
                                            <span className="sr-only">
                                                {t('(needs attention)')}
                                            </span>
                                        </>
                                    ) : null}
                                </TabsTrigger>
                            ))}
                        </TabsList>

                        {/*
                         * Force-mounted: a panel React Aria has unmounted
                         * submits nothing, and an absent field is not an empty
                         * one — saving from any single tab would blank every
                         * setting on the tabs the reader had not opened.
                         */}
                        <TabsContent
                            id="security"
                            shouldForceMount
                            className={INACTIVE_PANEL_CLASS}
                        >
                            <FirmSecuritySection
                                security={settings.security}
                                error={errors.two_factor_enforcement}
                            />
                        </TabsContent>

                        <TabsContent
                            id="portal"
                            shouldForceMount
                            className={INACTIVE_PANEL_CLASS}
                        >
                            <FirmPortalSection
                                portal={settings.portal}
                                errors={errors}
                            />
                        </TabsContent>

                        <TabsContent
                            id="reminders"
                            shouldForceMount
                            className={INACTIVE_PANEL_CLASS}
                        >
                            <FirmReminderSection
                                reminders={settings.reminders}
                                errors={errors}
                            />
                        </TabsContent>

                        <TabsContent
                            id="retention"
                            shouldForceMount
                            className={INACTIVE_PANEL_CLASS}
                        >
                            <FirmRetentionSection
                                retention={settings.retention}
                                errors={errors}
                            />
                        </TabsContent>

                        <TabsContent
                            id="assisted"
                            shouldForceMount
                            className={INACTIVE_PANEL_CLASS}
                        >
                            <FirmAiSection ai={settings.ai} />
                        </TabsContent>

                        {tab === 'details' ? null : (
                            <Field orientation="horizontal">
                                <Button type="submit" isDisabled={processing}>
                                    {processing ? <Spinner /> : null}
                                    {processing
                                        ? t('Saving…')
                                        : t('Save settings')}
                                </Button>
                            </Field>
                        )}
                    </>
                )}
            </Form>

            <TabsContent id="details">
                <FirmIdentitySection firm={firm} />
            </TabsContent>
        </Tabs>
    );
}
