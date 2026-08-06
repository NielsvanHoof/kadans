import type { DataTableColumn } from '@/components/data/data-table';
import DataTableBulkToolbar from '@/components/data/data-table-bulk-toolbar';
import IndexDataCard from '@/components/data/index-data-card';
import EmptyState from '@/components/primitives/empty-state';
import BulkRestoreClientsButton from '@/features/clients/list/bulk-restore-clients-button';
import RestoreClientButton from '@/features/clients/list/restore-client-button';
import type { ArchivedClientSummary } from '@/features/clients/types';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format-date-time';
import type { Paginated } from '@/types/pagination';

export default function ArchivedClientsCard({
    clients,
    canRestore,
}: {
    clients: Paginated<ArchivedClientSummary>;
    canRestore: boolean;
}) {
    const { t, locale } = useTranslation();
    const rows = clients.data;

    const columns: DataTableColumn<ArchivedClientSummary>[] = [
        {
            key: 'name',
            header: t('Client'),
            className: 'whitespace-normal',
            cell: (client) => (
                <div className="min-w-0">
                    <p className="font-medium">{client.name}</p>
                    <p className="truncate text-sm text-muted-foreground">
                        {client.email}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {t('Archived :datetime', {
                            datetime: formatDateTime(
                                client.archived_at,
                                locale,
                            ),
                        })}
                        {' · '}
                        {client.dossiers_count === 1
                            ? t('1 dossier retained')
                            : t(':count dossiers retained', {
                                  count: client.dossiers_count,
                              })}
                    </p>
                </div>
            ),
        },
        ...(canRestore
            ? [
                  {
                      key: 'actions',
                      header: t('Actions'),
                      className: 'text-right',
                      cell: (client: ArchivedClientSummary) => (
                          <RestoreClientButton
                              clientId={client.id}
                              clientName={client.name}
                          />
                      ),
                  } satisfies DataTableColumn<ArchivedClientSummary>,
              ]
            : []),
    ];

    return (
        <IndexDataCard
            title={t('Archived clients')}
            description={
                clients.total === 1
                    ? t('1 archived client')
                    : t(':count archived clients', {
                          count: clients.total,
                      })
            }
            columns={columns}
            rows={rows}
            rowKey={(client) => client.id}
            bulkActions={
                canRestore
                    ? ({ selectedRows, clearSelection }) => (
                          <DataTableBulkToolbar
                              selectedCount={selectedRows.length}
                              actions={
                                  <BulkRestoreClientsButton
                                      selectedIds={selectedRows.map(
                                          (client) => client.id,
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
                    title={t('No archived clients.')}
                    description={t(
                        'Clients you archive will remain available here during the retention period.',
                    )}
                />
            }
        />
    );
}
