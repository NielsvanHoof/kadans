import { Head } from '@inertiajs/react';
import FirmOnboardingCard from '@/features/workspaces/firm-onboarding-card';
import { useTranslation } from '@/hooks/use-translation';

export default function FirmOnboarding() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Set up your firm')} />
            <FirmOnboardingCard />
        </>
    );
}
