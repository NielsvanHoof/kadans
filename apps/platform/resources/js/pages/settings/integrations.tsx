import { Head, setLayoutProps } from '@inertiajs/react';
import { Link2 } from 'lucide-react';
import EmptyState from '@/components/primitives/empty-state';
import Heading from '@/components/primitives/heading';
import ManageConnectedAccounts from '@/features/integrations/manage-connected-accounts';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editIntegrations } from '@/routes/integrations';

type OAuthConnectionSummary = {
    provider: string;
    email: string | null;
};

type Props = {
    oauthProviders: string[];
    oauthConnections: OAuthConnectionSummary[];
};

export default function Integrations({
    oauthProviders,
    oauthConnections,
}: Props) {
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Integrations'),
                href: editIntegrations(),
            },
        ],
    });

    return (
        <>
            <Head title={t('Integrations')} />

            <h1 className="sr-only">{t('Integrations')}</h1>

            <div className="space-y-6">
                <Heading
                    level={2}
                    variant="small"
                    title={t('Integrations')}
                    description={t(
                        'Connect Google, Microsoft, and other apps to sign in faster and unlock other features.',
                    )}
                />

                {oauthProviders.length > 0 ? (
                    <ManageConnectedAccounts
                        oauthProviders={oauthProviders}
                        oauthConnections={oauthConnections}
                    />
                ) : (
                    <EmptyState
                        variant="panel"
                        icon={Link2}
                        title={t('No integrations available')}
                        description={t(
                            "Single sign-on isn't set up yet. Contact your administrator.",
                        )}
                    />
                )}
            </div>
        </>
    );
}
