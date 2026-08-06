import { Link } from '@inertiajs/react';
import type { DataTableColumn } from '@/components/data/data-table';
import DataTableBulkToolbar from '@/components/data/data-table-bulk-toolbar';
import IndexDataCard from '@/components/data/index-data-card';
import EmptyState from '@/components/primitives/empty-state';
import { LinkButton } from '@/components/ui/button';
import BulkArchiveDossiersButton from '@/features/dossiers/list/bulk-archive-dossiers-button';
import DossierStatusBadge from '@/features/dossiers/status/dossier-status-badge';
import type { DossierSummary } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate } from '@/lib/format-date-time';
import { create, show } from '@/routes/workspaces/dossiers';
import type { Paginated } from '@/types/pagination';

/**
 * Full dossier list for the dossiers index page.
 */
export default function DossiersListCard({
    dossiers,
    canManage = false,
}: {
    dossiers: Paginated<DossierSummary>;
    canManage?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t, locale } = useTranslation();
    const rows = dossiers.data;
    const total = dossiers.total;

    const columns: DataTableColumn<DossierSummary>[] = [
        {
            key: 'title',
            header: t('Title'),
            className: 'whitespace-normal',
            cell: (dossier) => (
                <Link
                    href={show({
                        tenant: currentWorkspace,
                        dossier: dossier.id,
                    })}
                    prefetch
                    className="font-medium hover:underline"
                >
                    {dossier.title}
                </Link>
            ),
        },
        {
            key: 'client',
            header: t('Client'),
            className: 'hidden whitespace-normal sm:table-cell',
            cell: (dossier) => (
                <div className="text-sm text-muted-foreground">
                    {dossier.client_name}
                    {dossier.reference ? ` · ${dossier.reference}` : null}
                </div>
            ),
        },
        {
            key: 'due_date',
            header: t('Due'),
            className: 'hidden md:table-cell',
            cell: (dossier) => (
                <span className="text-sm text-muted-foreground">
                    {dossier.due_date
                        ? formatDate(dossier.due_date, locale)
                        : t('No due date')}
                </span>
            ),
        },
        {
            key: 'status',
            header: t('Status'),
            cell: (dossier) => <DossierStatusBadge status={dossier.status} />,
        },
    ];

    return (
        <IndexDataCard
            title={t('All dossiers')}
            description={
                total === 1
                    ? t('1 dossier')
                    : t(':count dossiers', { count: total })
            }
            columns={columns}
            rows={rows}
            rowKey={(dossier) => dossier.id}
            bulkActions={
                canManage
                    ? ({ selectedRows, clearSelection }) => (
                          <DataTableBulkToolbar
                              selectedCount={selectedRows.length}
                              actions={
                                  <BulkArchiveDossiersButton
                                      selectedIds={selectedRows.map(
                                          (dossier) => dossier.id,
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
                    title={t('Create a dossier to start collecting documents.')}
                    action={
                        <LinkButton
                            size="sm"
                            href={create(currentWorkspace).url}
                        >
                            {t('New dossier')}
                        </LinkButton>
                    }
                />
            }
        />
    );
}
