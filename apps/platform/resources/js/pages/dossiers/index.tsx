import { Head, setLayoutProps } from '@inertiajs/react';
import { Archive, Plus } from 'lucide-react';
import IndexPagination from '@/components/data/index-query/index-pagination';
import IndexQueryResults from '@/components/data/index-query/index-query-results';
import IndexQueryToolbar from '@/components/data/index-query/index-query-toolbar';
import Heading from '@/components/primitives/heading';
import { LinkButton } from '@/components/ui/button';
import DossiersListCard from '@/features/dossiers/list/dossiers-list-card';
import type { DossierSummary } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as clientIndex } from '@/routes/workspaces/clients';
import {
    archived as archivedDossiers,
    create,
    index as dossierIndex,
} from '@/routes/workspaces/dossiers';
import type { IndexQueryConfig, Paginated } from '@/types/pagination';

/**
 * Dossiers index — list of all firm dossiers.
 */
export default function DossierIndex({
    dossiers,
    indexQuery,
    can_manage: canManage = false,
}: {
    dossiers: Paginated<DossierSummary>;
    indexQuery: IndexQueryConfig;
    can_manage?: boolean;
    /** Current Spatie filter bag — consumed by useIndexQuery via usePage(). */
    filters?: Record<string, string>;
    sort?: string | null;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Dossiers'),
                href: dossierIndex(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Dossiers')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        level={1}
                        className="mb-0"
                        title={t('Dossiers')}
                        description={t(
                            'Request and review documents for each client.',
                        )}
                    />

                    <div className="flex flex-wrap gap-2">
                        <LinkButton
                            variant="ghost"
                            href={archivedDossiers(currentWorkspace).url}
                        >
                            <Archive aria-hidden="true" />
                            {t('Archived')}
                        </LinkButton>
                        <LinkButton
                            variant="outline"
                            href={clientIndex(currentWorkspace).url}
                        >
                            {t('Clients')}
                        </LinkButton>
                        <LinkButton href={create(currentWorkspace).url}>
                            <Plus />
                            {t('New dossier')}
                        </LinkButton>
                    </div>
                </div>

                <IndexQueryToolbar
                    config={indexQuery}
                    only={[
                        'dossiers',
                        'filters',
                        'sort',
                        'indexQuery',
                        'flash',
                    ]}
                />
                <IndexQueryResults className="space-y-6">
                    <DossiersListCard
                        dossiers={dossiers}
                        canManage={canManage}
                    />
                    <IndexPagination paginator={dossiers} />
                </IndexQueryResults>
            </div>
        </>
    );
}
