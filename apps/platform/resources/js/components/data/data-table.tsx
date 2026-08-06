import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import type { Selection, SortDescriptor } from 'react-aria-components';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export type DataTableColumn<T> = {
    key: string;
    header: ReactNode;
    /** When set, the header uses React Aria column sorting (aria-sort). */
    sortable?: boolean;
    className?: string;
    cell: (row: T) => ReactNode;
};

type DataTableProps<T> = {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    /** Current sort column key, if any. */
    sortKey?: string | null;
    sortDirection?: 'asc' | 'desc' | null;
    onSort?: (key: string) => void;
    /** When set, enables row checkboxes and a select-all header control. */
    selectedKeys?: Set<string | number>;
    onSelectionChange?: (keys: Set<string | number>) => void;
    /** Optional toolbar above the table (e.g. bulk actions). */
    toolbar?: ReactNode;
    /** Accessible name for the table — React Aria requires one. */
    ariaLabel?: string;
    className?: string;
    emptyState?: ReactNode;
};

/**
 * Composable table built on shadcn Table primitives.
 * Supports optional sortable headers and row selection.
 *
 * Selection is owned by React Aria's Table, not by this component: the
 * checkboxes are `slot="selection"` and read their state from the table's
 * collection. Rendering a controlled Checkbox inside a Row instead throws
 * ("A slot prop is required"), because the row already provides the context.
 *
 * Sorting also goes through React Aria (`allowsSorting` + `sortDescriptor`)
 * so column headers expose `aria-sort` instead of a hand-rolled button.
 */
export default function DataTable<T>({
    columns,
    rows,
    rowKey,
    sortKey = null,
    sortDirection = null,
    onSort,
    selectedKeys,
    onSelectionChange,
    toolbar,
    ariaLabel,
    className,
    emptyState = null,
}: DataTableProps<T>) {
    const { t } = useTranslation();
    const selectionEnabled =
        selectedKeys !== undefined && onSelectionChange !== undefined;

    // Map our asc/desc API onto React Aria's ascending/descending descriptor.
    const sortDescriptor: SortDescriptor | undefined =
        sortKey && sortDirection
            ? {
                  column: sortKey,
                  direction:
                      sortDirection === 'asc' ? 'ascending' : 'descending',
              }
            : undefined;

    /**
     * React Aria reports "select all" as the literal string `all` rather than
     * enumerating keys, so expand it before handing it back to callers that
     * expect a plain Set.
     */
    function handleSelectionChange(selection: Selection): void {
        if (!onSelectionChange) {
            return;
        }

        if (selection === 'all') {
            onSelectionChange(new Set(rows.map((row) => rowKey(row))));
            return;
        }

        onSelectionChange(new Set(selection as Set<string | number>));
    }

    function handleSortChange(descriptor: SortDescriptor): void {
        if (!onSort || descriptor.column == null) {
            return;
        }

        // Callers own the toggle / URL sync; they only need the column key.
        onSort(String(descriptor.column));
    }

    if (rows.length === 0 && emptyState !== null) {
        return <>{emptyState}</>;
    }

    return (
        <div className={cn('space-y-3', className)}>
            {toolbar}

            <div className="rounded-md border">
                {/* `ledger-rows` is the green-bar striping from ledger paper:
                    alternate rows carry a faint tint so the eye can track a
                    wide row across without needing a rule between every one. */}
                {/* React Aria's Table is a collection, not markup: columns sit
                    directly under TableHeader (no Row), the table needs a
                    label, and exactly one column must be the row header. */}
                <Table
                    className="ledger-rows"
                    aria-label={ariaLabel ?? t('Data table')}
                    selectionMode={selectionEnabled ? 'multiple' : 'none'}
                    selectedKeys={selectedKeys}
                    onSelectionChange={handleSelectionChange}
                    sortDescriptor={sortDescriptor}
                    onSortChange={onSort ? handleSortChange : undefined}
                >
                    <TableHeader>
                        {selectionEnabled ? (
                            <TableHead className="w-10">
                                <Checkbox slot="selection" />
                            </TableHead>
                        ) : null}

                        {columns.map((column, index) => {
                            const isSortable = Boolean(
                                column.sortable && onSort,
                            );
                            const isActiveSort = sortKey === column.key;

                            return (
                                <TableHead
                                    key={column.key}
                                    id={column.key}
                                    isRowHeader={index === 0}
                                    allowsSorting={isSortable}
                                    className={cn(
                                        column.className,
                                        isSortable &&
                                            'cursor-pointer font-medium hover:text-foreground',
                                    )}
                                >
                                    {isSortable ? (
                                        <span className="inline-flex items-center gap-1">
                                            {column.header}
                                            <SortIcon
                                                active={isActiveSort}
                                                direction={
                                                    isActiveSort
                                                        ? sortDirection
                                                        : null
                                                }
                                            />
                                        </span>
                                    ) : (
                                        column.header
                                    )}
                                </TableHead>
                            );
                        })}
                    </TableHeader>

                    <TableBody>
                        {rows.map((row) => {
                            // `id` stays the raw key — React Aria's Key is
                            // string | number, and stringifying it here would
                            // stop numeric selectedKeys from matching.
                            const key = rowKey(row);

                            return (
                                <TableRow key={key} id={key}>
                                    {selectionEnabled ? (
                                        <TableCell>
                                            <Checkbox slot="selection" />
                                        </TableCell>
                                    ) : null}

                                    {columns.map((column) => (
                                        <TableCell
                                            key={column.key}
                                            className={column.className}
                                        >
                                            {column.cell(row)}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

function SortIcon({
    active,
    direction,
}: {
    active: boolean;
    direction: 'asc' | 'desc' | null;
}) {
    if (!active || direction === null) {
        return (
            <ArrowUpDown
                className="size-3.5 text-muted-foreground"
                aria-hidden
            />
        );
    }

    if (direction === 'asc') {
        return <ArrowUp className="size-3.5" aria-hidden />;
    }

    return <ArrowDown className="size-3.5" aria-hidden />;
}
