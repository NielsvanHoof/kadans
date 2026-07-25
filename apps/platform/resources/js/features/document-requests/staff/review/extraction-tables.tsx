import EmptyState from '@/components/empty-state';
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
                                <div className="overflow-x-auto rounded-md border">
                                    <table className="w-full min-w-md border-collapse text-left text-sm">
                                        {headers.length > 0 && (
                                            <thead>
                                                <tr className="border-b bg-muted/40">
                                                    {headers.map(
                                                        ({
                                                            value: header,
                                                            key,
                                                        }) => (
                                                            <th
                                                                key={`${tableId}|h|${key}`}
                                                                className="px-3 py-2 font-medium"
                                                            >
                                                                {header}
                                                            </th>
                                                        ),
                                                    )}
                                                </tr>
                                            </thead>
                                        )}
                                        <tbody>
                                            {rows.map(
                                                ({ row, key: rowKey }) => {
                                                    const rowId = `${tableId}|r|${rowKey}`;
                                                    const cells =
                                                        keyedCells(row);

                                                    return (
                                                        <tr
                                                            key={rowId}
                                                            className="border-b last:border-b-0"
                                                        >
                                                            {cells.map(
                                                                ({
                                                                    cell,
                                                                    key,
                                                                }) => (
                                                                    <td
                                                                        key={`${rowId}|${key}`}
                                                                        className="px-3 py-2 align-top whitespace-pre-wrap"
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
