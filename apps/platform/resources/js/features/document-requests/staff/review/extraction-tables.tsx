/*
 * Plain semantic <table> rather than the React Aria Table primitive.
 * This grid uses colSpan group-separator rows, which a React Aria collection
 * cannot express, and it needs none of the collection behaviour (no selection,
 * no row actions). Native table semantics are fully accessible here.
 */
import EmptyState from '@/components/primitives/empty-state';
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
                                    <table className="w-full min-w-md caption-bottom text-left text-xs">
                                        {headers.length > 0 && (
                                            <thead className="[&_tr]:border-b">
                                                <tr className="border-b bg-muted/40 transition-colors hover:bg-muted/40 hover:bg-muted/50">
                                                    {headers.map(
                                                        ({
                                                            value: header,
                                                            key,
                                                        }) => (
                                                            <th
                                                                className="p-2 text-left align-middle font-medium"
                                                                key={`${tableId}|h|${key}`}
                                                            >
                                                                {header}
                                                            </th>
                                                        ),
                                                    )}
                                                </tr>
                                            </thead>
                                        )}
                                        <tbody className="[&_tr:last-child]:border-0">
                                            {rows.map(
                                                ({ row, key: rowKey }) => {
                                                    const rowId = `${tableId}|r|${rowKey}`;
                                                    const cells =
                                                        keyedCells(row);

                                                    return (
                                                        <tr
                                                            className="border-b transition-colors hover:bg-muted/50"
                                                            key={rowId}
                                                        >
                                                            {cells.map(
                                                                ({
                                                                    cell,
                                                                    key,
                                                                }) => (
                                                                    <td
                                                                        className="p-2 align-top whitespace-pre-wrap"
                                                                        key={`${rowId}|${key}`}
                                                                    >
                                                                        {cell}
                                                                    </td>
                                                                ),
                                                            )}
                                                        </tr>
                                                    );
                                                },
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
