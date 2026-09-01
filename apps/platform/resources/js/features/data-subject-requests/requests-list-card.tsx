import { ShieldQuestion } from 'lucide-react';
import EmptyState from '@/components/primitives/empty-state';
import { Card } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import type { Paginated } from '@/types/pagination';
import RequestActions from './request-actions';
import RequestStatusBadge from './request-status-badge';
import type { DataSubjectRequest } from './types';

export default function RequestsListCard({
    requests,
    canManage,
}: {
    requests: Paginated<DataSubjectRequest>;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();
    const rows = requests.data;

    if (rows.length === 0) {
        return (
            <EmptyState
                icon={ShieldQuestion}
                title={t('No requests logged')}
                description={t(
                    'Log a request when a client asks for a copy of their data or for it to be erased.',
                )}
            />
        );
    }

    // React Aria's Table is a collection, not HTML markup: columns sit
    // directly under TableHeader (no Row), the table needs a label, and
    // exactly one column must be the row header. Wrapping heads in a Row
    // collapses them to one column and throws "Found N cells and 1 columns".
    return (
        <Card className="overflow-x-auto p-0">
            <Table aria-label={t('Privacy requests')}>
                <TableHeader>
                    <TableHead isRowHeader>{t('Client')}</TableHead>
                    <TableHead className="hidden sm:table-cell">
                        {t('Request')}
                    </TableHead>
                    <TableHead>{t('Status')}</TableHead>
                    <TableHead className="hidden md:table-cell">
                        {t('Due')}
                    </TableHead>
                    <TableHead className="text-right">{t('Actions')}</TableHead>
                </TableHeader>
                <TableBody>
                    {rows.map((request) => (
                        <TableRow key={request.id} id={String(request.id)}>
                            <TableCell className="font-medium">
                                {request.client_name}
                                {/* Request type and due date are hidden as
                                    columns below sm:/md:, so surface them
                                    inline here — hiding a column must never
                                    make its data unreachable. */}
                                <p className="text-muted-foreground text-xs font-normal sm:hidden">
                                    {request.type_label}
                                </p>
                                <p className="text-muted-foreground text-xs font-normal md:hidden">
                                    {formatDateTime(request.due_at)}
                                </p>
                            </TableCell>
                            <TableCell className="hidden sm:table-cell">
                                {request.type_label}
                                {request.refusal_reason ? (
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {request.refusal_reason}
                                    </p>
                                ) : null}
                            </TableCell>
                            <TableCell>
                                <RequestStatusBadge request={request} />
                            </TableCell>
                            <TableCell className="hidden whitespace-nowrap md:table-cell">
                                {formatDateTime(request.due_at)}
                            </TableCell>
                            <TableCell>
                                <div className="flex justify-end">
                                    <RequestActions
                                        request={request}
                                        canManage={canManage}
                                    />
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </Card>
    );
}
