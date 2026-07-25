import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

export type DataTableColumn<T> = {
    key: string;
    header: ReactNode;
    /** When set, the header renders as a sort button calling onSort(key). */
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
    className?: string;
    emptyState?: ReactNode;
};

/**
 * Composable table built on shadcn Table primitives.
 * Supports optional sortable headers and row selection.
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
    className,
    emptyState = null,
}: DataTableProps<T>) {
    const selectionEnabled =
        selectedKeys !== undefined && onSelectionChange !== undefined;

    const allSelected =
        selectionEnabled &&
        rows.length > 0 &&
        rows.every((row) => selectedKeys.has(rowKey(row)));

    const someSelected =
        selectionEnabled &&
        rows.some((row) => selectedKeys.has(rowKey(row))) &&
        !allSelected;

    function toggleAll(): void {
        if (!selectionEnabled) {
            return;
        }

        if (allSelected) {
            onSelectionChange(new Set());
            return;
        }

        onSelectionChange(new Set(rows.map((row) => rowKey(row))));
    }

    function toggleRow(row: T): void {
        if (!selectionEnabled) {
            return;
        }

        const key = rowKey(row);
        const next = new Set(selectedKeys);

        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }

        onSelectionChange(next);
    }

    if (rows.length === 0 && emptyState !== null) {
        return <>{emptyState}</>;
    }

    return (
        <div className={cn('space-y-3', className)}>
            {toolbar}

            <div className="rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {selectionEnabled ? (
                                <TableHead className="w-10">
                                    <Checkbox
                                        checked={
                                            allSelected
                                                ? true
                                                : someSelected
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={toggleAll}
                                        aria-label="Select all rows"
                                    />
                                </TableHead>
                            ) : null}

                            {columns.map((column) => (
                                <TableHead
                                    key={column.key}
                                    className={column.className}
                                >
                                    {column.sortable && onSort ? (
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-1 font-medium hover:text-foreground"
                                            onClick={() => onSort(column.key)}
                                        >
                                            {column.header}
                                            <SortIcon
                                                active={sortKey === column.key}
                                                direction={
                                                    sortKey === column.key
                                                        ? sortDirection
                                                        : null
                                                }
                                            />
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {rows.map((row) => {
                            const key = rowKey(row);
                            const selected =
                                selectionEnabled && selectedKeys.has(key);

                            return (
                                <TableRow
                                    key={key}
                                    data-state={
                                        selected ? 'selected' : undefined
                                    }
                                >
                                    {selectionEnabled ? (
                                        <TableCell>
                                            <Checkbox
                                                checked={selected}
                                                onCheckedChange={() =>
                                                    toggleRow(row)
                                                }
                                                aria-label="Select row"
                                            />
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
