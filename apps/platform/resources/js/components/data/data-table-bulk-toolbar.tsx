import type { ReactNode } from 'react';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Selection bar shown above a DataTable when rows are selected.
 * Hosts bulk action controls in the actions slot.
 */
export default function DataTableBulkToolbar({
    selectedCount,
    actions,
}: {
    selectedCount: number;
    actions: ReactNode;
}) {
    const { t } = useTranslation();

    if (selectedCount === 0) {
        return null;
    }

    return (
        <div className="bg-muted/40 flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-2">
            <p className="text-sm font-medium">
                {selectedCount === 1
                    ? t('1 selected')
                    : t(':count selected', { count: selectedCount })}
            </p>
            <div className="flex flex-wrap items-center gap-2">{actions}</div>
        </div>
    );
}
