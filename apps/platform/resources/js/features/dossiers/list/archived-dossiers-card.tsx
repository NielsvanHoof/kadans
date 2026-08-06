import type { DataTableColumn } from '@/components/data/data-table';
import DataTableBulkToolbar from '@/components/data/data-table-bulk-toolbar';
import IndexDataCard from '@/components/data/index-data-card';
import EmptyState from '@/components/primitives/empty-state';
import BulkRestoreDossiersButton from '@/features/dossiers/list/bulk-restore-dossiers-button';
import RestoreDossierButton from '@/features/dossiers/list/restore-dossier-button';
import DossierStatusBadge from '@/features/dossiers/status/dossier-status-badge';
import type { ArchivedDossierSummary } from '@/features/dossiers/types';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format-date-time';
import type { Paginated } from '@/types/pagination';

export default function ArchivedDossiersCard({
    dossiers,
    canRestore,
    canRestoreClients,
}: {
    dossiers: Paginated<ArchivedDossierSummary>;
    canRestore: boolean;
    canRestoreClients: boolean;
}) {
    const { t, locale } = useTranslation();
    const rows = dossiers.data;

    const columns: DataTableColumn<ArchivedDossierSummary>[] = [
        {
            key: 'title',
            header: t('Title'),
            className: 'whitespace-normal',
            cell: (dossier) => (
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-medium">{dossier.title}</p>
                        <DossierStatusBadge status={dossier.status} />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {dossier.client_name}
                        {dossier.reference ? ` · ${dossier.reference}` : null}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {t('Archived :datetime', {
                            datetime: formatDateTime(
                                dossier.archived_at,
                                locale,
                            ),
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
                      cell: (dossier: ArchivedDossierSummary) => (
                          <RestoreDossierButton
                              dossierId={dossier.id}
                              clientArchived={dossier.client_archived}
                              canRestoreClient={canRestoreClients}
                          />
                      ),
                  } satisfies DataTableColumn<ArchivedDossierSummary>,
              ]
            : []),
    ];

    return (
        <IndexDataCard
            title={t('Archived dossiers')}
            description={
                dossiers.total === 1
                    ? t('1 archived dossier')
                    : t(':count archived dossiers', {
                          count: dossiers.total,
                      })
            }
            columns={columns}
            rows={rows}
            rowKey={(dossier) => dossier.id}
            bulkActions={
                canRestore
                    ? ({ selectedRows, clearSelection }) => {
                          // Bulk restore only submits dossiers whose client is active.
                          const restorableSelectedIds = selectedRows
                              .filter((dossier) => !dossier.client_archived)
                              .map((dossier) => dossier.id);
                          const selectedBlockedCount = selectedRows.filter(
                              (dossier) => dossier.client_archived,
                          ).length;

                          if (
                              restorableSelectedIds.length === 0 &&
                              selectedBlockedCount === 0
                          ) {
                              return null;
                          }

                          return (
                              <DataTableBulkToolbar
                                  selectedCount={
                                      restorableSelectedIds.length +
                                      selectedBlockedCount
                                  }
                                  actions={
                                      <>
                                          {selectedBlockedCount > 0 ? (
                                              <p className="max-w-64 text-right text-xs text-muted-foreground">
                                                  {selectedBlockedCount === 1
                                                      ? t(
                                                            '1 selected dossier needs its client restored first.',
                                                        )
                                                      : t(
                                                            ':count selected dossiers need their client restored first.',
                                                            {
                                                                count: selectedBlockedCount,
                                                            },
                                                        )}
                                              </p>
                                          ) : null}
                                          <BulkRestoreDossiersButton
                                              selectedIds={
                                                  restorableSelectedIds
                                              }
                                              onSuccess={clearSelection}
                                          />
                                      </>
                                  }
                              />
                          );
                      }
                    : undefined
            }
            emptyState={
                <EmptyState
                    title={t('No archived dossiers.')}
                    description={t(
                        'Dossiers you archive will remain available here during the retention period.',
                    )}
                />
            }
        />
    );
}
