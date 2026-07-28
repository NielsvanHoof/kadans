import EmptyState from '@/components/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    keyedCells,
    keyedRows,
    keyedStrings,
    keyedTablesList,
} from '@/features/document-requests/staff/review/document-extraction-keys';
import type { DocumentExtractionTable } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Tabular data extracted from the document.
 */
export default function ExtractionTables({
    tables,
}: {
    tables: DocumentExtractionTable[];
}) {
    const { t } = useTranslation();
    const keyedTables = keyedTablesList(tables);

    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold tracking-tight">
                {t('Tables')}
            </h2>
            {keyedTables.length === 0 ? (
                <EmptyState title={t('No tables detected.')} />
            ) : (
                <div className="space-y-4">
                    {keyedTables.map(({ table, key: tableId }) => {
                        const headers = keyedStrings(table.headers);
                        const rows = keyedRows(table.rows);

                        return (
                            <div key={tableId} className="space-y-2">
                                {table.title !== null && table.title !== '' && (
                                    <h3 className="text-sm font-medium">
                                        {table.title}
                                    </h3>
                                )}
                                <div className="rounded-md border">
                                    <Table className="min-w-md text-left">
                                        {headers.length > 0 && (
                                            <TableHeader>
                                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                                    {headers.map(
                                                        ({
                                                            value: header,
                                                            key,
                                                        }) => (
                                                            <TableHead
                                                                key={`${tableId}|h|${key}`}
                                                            >
                                                                {header}
                                                            </TableHead>
                                                        ),
                                                    )}
                                                </TableRow>
                                            </TableHeader>
                                        )}
                                        <TableBody>
                                            {rows.map(
                                                ({ row, key: rowKey }) => {
                                                    const rowId = `${tableId}|r|${rowKey}`;
                                                    const cells =
                                                        keyedCells(row);

                                                    return (
                                                        <TableRow key={rowId}>
                                                            {cells.map(
                                                                ({
                                                                    cell,
                                                                    key,
                                                                }) => (
                                                                    <TableCell
                                                                        key={`${rowId}|${key}`}
                                                                        className="align-top whitespace-pre-wrap"
                                                                    >
                                                                        {cell}
                                                                    </TableCell>
                                                                ),
                                                            )}
                                                        </TableRow>
                                                    );
                                                },
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
