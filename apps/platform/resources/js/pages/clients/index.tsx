import { Head, setLayoutProps } from '@inertiajs/react';
import { Archive, Plus } from 'lucide-react';
import IndexPagination from '@/components/data/index-query/index-pagination';
import IndexQueryResults from '@/components/data/index-query/index-query-results';
import IndexQueryToolbar from '@/components/data/index-query/index-query-toolbar';
import Heading from '@/components/primitives/heading';
import { LinkButton } from '@/components/ui/button';
import ClientsListCard from '@/features/clients/list/clients-list-card';
import type { ClientSummary } from '@/features/clients/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    archived as archivedClients,
    index as clientIndex,
    create as createClient,
} from '@/routes/workspaces/clients';
import { create as createDossier } from '@/routes/workspaces/dossiers';
import type { IndexQueryConfig, Paginated } from '@/types/pagination';

/**
 * Clients index — people and organisations for document collection.
 */
export default function ClientIndex({
    clients,
    indexQuery,
    can_manage: canManage = false,
}: {
    clients: Paginated<ClientSummary>;
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
                title: t('Clients'),
                href: clientIndex(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Clients')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        level={1}
                        className="mb-0"
                        title={t('Clients')}
                        description={t(
                            'Manage the people and organisations you collect documents from.',
                        )}
                    />

                    <div className="flex flex-wrap gap-2">
                        <LinkButton
                            variant="ghost"
                            href={archivedClients(currentWorkspace).url}
                        >
                            <Archive aria-hidden="true" />
                            {t('Archived')}
                        </LinkButton>
                        <LinkButton
                            variant="outline"
                            href={createDossier(currentWorkspace).url}
                        >
                            {t('New dossier')}
                        </LinkButton>
                        <LinkButton href={createClient(currentWorkspace).url}>
                            <Plus />
                            {t('New client')}
                        </LinkButton>
                    </div>
                </div>

                <IndexQueryToolbar
                    config={indexQuery}
                    only={['clients', 'filters', 'sort', 'indexQuery', 'flash']}
                />
                <IndexQueryResults className="space-y-6">
                    <ClientsListCard clients={clients} canManage={canManage} />
                    <IndexPagination paginator={clients} />
                </IndexQueryResults>
            </div>
        </>
    );
}
