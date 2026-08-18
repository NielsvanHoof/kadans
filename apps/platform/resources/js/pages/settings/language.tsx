import { Head, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/primitives/heading';
import LocaleTabs from '@/features/settings/locale-tabs';
import TimezoneSelect from '@/features/settings/timezone-select';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editLanguage } from '@/routes/language';

export default function Language() {
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Language settings'),
                href: editLanguage(),
            },
        ],
    });

    return (
        <>
            <Head title={t('Language settings')} />

            <h1 className="sr-only">{t('Language settings')}</h1>

            <div className="space-y-6">
                <Heading
                    level={2}
                    variant="small"
                    title={t('Language settings')}
                    description={t(
                        'Update the language used across the application',
                    )}
                />
                <LocaleTabs />

                <Heading
                    level={2}
                    variant="small"
                    title={t('Timezone')}
                    description={t(
                        'Dates and times are shown in this timezone. Detected from your browser unless you choose one.',
                    )}
                />
                <TimezoneSelect />
            </div>
        </>
    );
}
