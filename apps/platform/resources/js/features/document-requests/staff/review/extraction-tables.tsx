/*
 * Plain semantic <table> rather than the React Aria Table primitive.
 * Textract regularly returns tables with no header row at all, which a React
 * Aria collection cannot express (it requires columns, one of them a row
 * header). Native table semantics handle that case and we need none of the
 * collection behaviour — no selection, no row actions.
 */
import { useMemo } from 'react';
import EmptyState from '@/components/primitives/empty-state';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    keyedCells,
    keyedRows,
    keyedStrings,
    keyedTablesList,
} from '@/features/document-requests/staff/review/document-extraction-keys';
import type { DocumentExtractionTable } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Tabular data extracted from the document, one collapsible block per table.
 */
export default function ExtractionTables({
    tables,
}: {
    tables: DocumentExtractionTable[];
}) {
    const { t } = useTranslation();
    const keyedTables = useMemo(() => keyedTablesList(tables), [tables]);

    if (keyedTables.length === 0) {
        return <EmptyState title={t('No tables detected.')} />;
    }

    return (
        <section className="space-y-2 pt-2">
            {keyedTables.map(({ table, key: tableId }, index) => {
                const columnCount = Math.max(
                    table.headers.length,
                    ...table.rows.map((row) => row.length),
                    0,
                );

                return (
                    <Collapsible
                        key={tableId}
                        defaultExpanded={index === 0}
                        className="rounded-md border"
                    >
                        <CollapsibleTrigger
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="w-full justify-between px-3"
                        >
                            <span className="font-medium">
                                {table.title !== null && table.title !== ''
                                    ? table.title
                                    : t('Table :number', {
                                          number: index + 1,
                                      })}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {t(':columns columns · :rows rows', {
                                    columns: columnCount,
                                    rows: table.rows.length,
                                })}
                            </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <ExtractionTable table={table} tableId={tableId} />
                        </CollapsibleContent>
                    </Collapsible>
                );
            })}
        </section>
    );
}

function ExtractionTable({
    table,
    tableId,
}: {
    table: DocumentExtractionTable;
    tableId: string;
}) {
    const headers = useMemo(() => keyedStrings(table.headers), [table.headers]);
    const rows = useMemo(() => keyedRows(table.rows), [table.rows]);

    return (
        <div className="overflow-x-auto border-t">
            <table className="w-full min-w-md caption-bottom text-left text-xs">
                {headers.length > 0 && (
                    <thead className="[&_tr]:border-b">
                        <tr className="border-b bg-muted/40">
                            {headers.map(({ value: header, key }) => (
                                <th
                                    className="sticky top-0 bg-muted/40 p-2 text-left align-middle font-medium"
                                    key={`${tableId}|h|${key}`}
                                >
                                    {header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                )}
                <tbody className="[&_tr:last-child]:border-0">
                    {rows.map(({ row, key: rowKey }) => {
                        const rowId = `${tableId}|r|${rowKey}`;
                        const cells = keyedCells(row);

                        return (
                            <tr
                                className="border-b transition-colors hover:bg-muted/50"
                                key={rowId}
                            >
                                {cells.map(({ cell, key }) => (
                                    <td
                                        className="p-2 align-top whitespace-pre-wrap"
                                        key={`${rowId}|${key}`}
                                    >
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
