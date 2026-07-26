import { Link } from '@inertiajs/react';
import { useState } from 'react';
import DataTable, { type DataTableColumn } from '@/components/data-table';
import DataTableBulkToolbar from '@/components/data-table-bulk-toolbar';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    const [selectedKeys, setSelectedKeys] = useState<Set<string | number>>(
        new Set(),
    );

    // Resolve selected rows for the bulk archive confirmation copy.
    const selectedIds = rows
        .filter((client) => selectedKeys.has(client.id))
        .map((client) => client.id);
    const totalDossiersCount = rows
        .filter((client) => selectedKeys.has(client.id))
        .reduce((sum, client) => sum + client.dossiers_count, 0);

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
        <Card>
            <CardHeader>
                <CardTitle>{t('All clients')}</CardTitle>
                <CardDescription>
                    {total === 1
                        ? t('1 client')
                        : t(':count clients', { count: total })}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(client) => client.id}
                    selectedKeys={canManage ? selectedKeys : undefined}
                    onSelectionChange={canManage ? setSelectedKeys : undefined}
                    toolbar={
                        canManage ? (
                            <DataTableBulkToolbar
                                selectedCount={selectedIds.length}
                                actions={
                                    <BulkArchiveClientsButton
                                        selectedIds={selectedIds}
                                        totalDossiersCount={totalDossiersCount}
                                        onSuccess={() =>
                                            setSelectedKeys(new Set())
                                        }
                                    />
                                }
                            />
                        ) : undefined
                    }
                    emptyState={
                        <EmptyState
                            title={t(
                                'Add your first client to create a dossier.',
                            )}
                            action={
                                <Button asChild size="sm">
                                    <Link href={createClient(currentWorkspace)}>
                                        {t('New client')}
                                    </Link>
                                </Button>
                            }
                        />
                    }
                />
            </CardContent>
        </Card>
    );
}
