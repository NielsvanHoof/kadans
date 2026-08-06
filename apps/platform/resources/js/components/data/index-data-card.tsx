import { type ReactNode, useState } from 'react';
import DataTable, { type DataTableColumn } from '@/components/data/data-table';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type IndexDataCardBulkContext<T> = {
    selectedKeys: Set<string | number>;
    selectedRows: T[];
    clearSelection: () => void;
};

type IndexDataCardProps<T> = {
    title: ReactNode;
    description: ReactNode;
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    emptyState: ReactNode;
    ariaLabel?: string;
    /**
     * When set, enables row selection and passes the selection into this
     * render prop for the DataTable toolbar (e.g. DataTableBulkToolbar).
     */
    bulkActions?: (ctx: IndexDataCardBulkContext<T>) => ReactNode;
};

/**
 * Shared list index shell: card chrome around a DataTable with optional
 * bulk-selection toolbar. Domain cards supply columns, empty state, and actions.
 */
export default function IndexDataCard<T>({
    title,
    description,
    columns,
    rows,
    rowKey,
    emptyState,
    ariaLabel,
    bulkActions,
}: IndexDataCardProps<T>) {
    const [selectedKeys, setSelectedKeys] = useState<Set<string | number>>(
        new Set(),
    );

    const selectionEnabled = bulkActions !== undefined;
    const selectedRows = selectionEnabled
        ? rows.filter((row) => selectedKeys.has(rowKey(row)))
        : [];

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={rowKey}
                    ariaLabel={ariaLabel}
                    selectedKeys={selectionEnabled ? selectedKeys : undefined}
                    onSelectionChange={
                        selectionEnabled ? setSelectedKeys : undefined
                    }
                    toolbar={
                        selectionEnabled
                            ? bulkActions({
                                  selectedKeys,
                                  selectedRows,
                                  clearSelection: () =>
                                      setSelectedKeys(new Set()),
                              })
                            : undefined
                    }
                    emptyState={emptyState}
                />
            </CardContent>
        </Card>
    );
}
