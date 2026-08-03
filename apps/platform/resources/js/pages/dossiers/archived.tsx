import { Head, setLayoutProps } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import IndexPagination from '@/components/data/index-query/index-pagination';
import IndexQueryResults from '@/components/data/index-query/index-query-results';
import IndexQueryToolbar from '@/components/data/index-query/index-query-toolbar';
import Heading from '@/components/primitives/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { LinkButton } from '@/components/ui/button';
import ArchivedDossiersCard from '@/features/dossiers/list/archived-dossiers-card';
import type { ArchivedDossierSummary } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    archived as archivedDossiers,
    index as dossierIndex,
} from '@/routes/workspaces/dossiers';
import type { IndexQueryConfig, Paginated } from '@/types/pagination';

export default function ArchivedDossiers({
    dossiers,
    indexQuery,
    can_restore: canRestore,
    can_restore_clients: canRestoreClients,
}: {
    dossiers: Paginated<ArchivedDossierSummary>;
    indexQuery: IndexQueryConfig;
    can_restore: boolean;
    can_restore_clients: boolean;
    filters?: Record<string, string>;
    sort?: string | null;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            { title: t('Dossiers'), href: dossierIndex(currentWorkspace) },
            {
                title: t('Archived dossiers'),
                href: archivedDossiers(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Archived dossiers')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        level={1}
                        className="mb-0"
                        title={t('Archived dossiers')}
                        description={t(
                            'Review retained dossier records without mixing them into active client work.',
                        )}
                    />
                    <LinkButton
                        variant="outline"
                        href={dossierIndex(currentWorkspace).url}
                    >
                        {t('Back to dossiers')}
                    </LinkButton>
                </div>

                <Alert>
                    <Archive aria-hidden="true" />
                    <AlertTitle>{t('Access stays closed')}</AlertTitle>
                    <AlertDescription>
                        {t(
                            'Restoring a dossier does not reactivate revoked portal links. Create a new link when the client should regain access.',
                        )}
                    </AlertDescription>
                </Alert>

                <IndexQueryToolbar config={indexQuery} />
                <IndexQueryResults className="space-y-6">
                    <ArchivedDossiersCard
                        dossiers={dossiers}
                        canRestore={canRestore}
                        canRestoreClients={canRestoreClients}
                    />
                    <IndexPagination paginator={dossiers} />
                </IndexQueryResults>
            </div>
        </>
    );
}
