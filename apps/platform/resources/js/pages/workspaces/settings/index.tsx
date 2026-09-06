import { Head, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/primitives/heading';
import FirmSettingsTabs from '@/features/workspaces/settings/firm-settings-tabs';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as settingsIndex } from '@/routes/workspaces/settings';

type WorkspaceSettingsPageProps = App.Tenancy.Data.WorkspaceSettingsPageData;

export default function WorkspaceSettingsIndex({
    settings,
}: WorkspaceSettingsPageProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Firm settings'),
                href: settingsIndex(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Firm settings')} />

            <div className="flex flex-col gap-6">
                <Heading
                    level={2}
                    className="mb-0"
                    title={t('Firm settings')}
                    description={t(
                        'Choices that apply to everyone at your firm.',
                    )}
                />

                <FirmSettingsTabs firm={currentWorkspace} settings={settings} />
            </div>
        </>
    );
}
