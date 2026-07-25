import { Link } from '@inertiajs/react';
import { useState } from 'react';
import DataTable, { type DataTableColumn } from '@/components/data-table';
import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    dossierStatusBadgeVariant,
    dossierStatusLabel,
} from '@/features/dossiers/status/dossier-status';
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
}: {
    dossiers: Paginated<DossierSummary>;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t, locale } = useTranslation();
    const rows = dossiers.data;
    const total = dossiers.total;
    const [selectedKeys, setSelectedKeys] = useState<Set<string | number>>(
        new Set(),
    );

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
            cell: (dossier) => (
                <Badge variant={dossierStatusBadgeVariant(dossier.status)}>
                    {dossierStatusLabel(dossier.status, t)}
                </Badge>
            ),
        },
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('All dossiers')}</CardTitle>
                <CardDescription>
                    {total === 1
                        ? t('1 dossier')
                        : t(':count dossiers', { count: total })}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(dossier) => dossier.id}
                    selectedKeys={selectedKeys}
                    onSelectionChange={setSelectedKeys}
                    emptyState={
                        <EmptyState
                            title={t(
                                'Create a dossier to start collecting documents.',
                            )}
                            action={
                                <Button asChild size="sm">
                                    <Link href={create(currentWorkspace)}>
                                        {t('New dossier')}
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
