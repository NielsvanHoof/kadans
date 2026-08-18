import { Link } from '@inertiajs/react';
import type { DataTableColumn } from '@/components/data/data-table';
import DataTableBulkToolbar from '@/components/data/data-table-bulk-toolbar';
import IndexDataCard from '@/components/data/index-data-card';
import EmptyState from '@/components/primitives/empty-state';
import { LinkButton } from '@/components/ui/button';
import BulkArchiveClientsButton from '@/features/clients/list/bulk-archive-clients-button';
import ArchiveClientButton from '@/features/clients/manage/archive-client-button';
import type { ClientSummary } from '@/features/clients/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    create as createClient,
    show as showClient,
} from '@/routes/workspaces/clients';
import type { Paginated } from '@/types/pagination';

/**
 * Client list for the clients index page.
 */
export default function ClientsListCard({
    clients,
    canManage = false,
}: {
    clients: Paginated<ClientSummary>;
    canManage?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const rows = clients.data;
    const total = clients.total;

    const columns: DataTableColumn<ClientSummary>[] = [
        {
            key: 'name',
            header: t('Client'),
            className: 'whitespace-normal',
            cell: (client) => (
                <div>
                    <Link
                        href={showClient({
                            tenant: currentWorkspace,
                            client: client.id,
                        })}
                        prefetch
                        className="font-medium hover:underline"
                    >
                        {client.name}
                    </Link>
                    <p className="text-sm text-muted-foreground sm:hidden">
                        {client.email}
                    </p>
                </div>
            ),
        },
        {
            key: 'email',
            header: t('Email'),
            className: 'hidden whitespace-normal sm:table-cell',
            cell: (client) => (
                <span className="text-sm text-muted-foreground">
                    {client.email}
                </span>
            ),
        },
        {
            key: 'dossiers',
            header: t('Dossiers'),
            cell: (client) => (
                <span className="text-sm text-muted-foreground">
                    {client.dossiers_count}{' '}
                    {client.dossiers_count === 1 ? t('dossier') : t('dossiers')}
                </span>
            ),
        },
        ...(canManage
            ? [
                  {
                      key: 'actions',
                      header: t('Actions'),
                      className: 'text-right',
                      cell: (client: ClientSummary) => (
                          <ArchiveClientButton
                              clientId={client.id}
                              clientName={client.name}
                              dossiersCount={client.dossiers_count}
                          />
                      ),
                  } satisfies DataTableColumn<ClientSummary>,
              ]
            : []),
    ];

    return (
        <IndexDataCard
            title={t('All clients')}
            description={
                total === 1
                    ? t('1 client')
                    : t(':count clients', { count: total })
            }
            columns={columns}
            rows={rows}
            rowKey={(client) => client.id}
            bulkActions={
                canManage
                    ? ({ selectedRows, clearSelection }) => (
                          <DataTableBulkToolbar
                              selectedCount={selectedRows.length}
                              actions={
                                  <BulkArchiveClientsButton
                                      selectedIds={selectedRows.map(
                                          (client) => client.id,
                                      )}
                                      totalDossiersCount={selectedRows.reduce(
                                          (sum, client) =>
                                              sum + client.dossiers_count,
                                          0,
                                      )}
                                      onSuccess={clearSelection}
                                  />
                              }
                          />
                      )
                    : undefined
            }
            emptyState={
                <EmptyState
                    title={t('Add your first client to create a dossier.')}
                    action={
                        <LinkButton
                            size="sm"
                            href={createClient(currentWorkspace).url}
                        >
                            {t('New client')}
                        </LinkButton>
                    }
                />
            }
        />
    );
}
