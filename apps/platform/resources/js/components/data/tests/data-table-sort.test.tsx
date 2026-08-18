import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: DataTable } = await import('@/components/data/data-table');

type Row = { id: number; name: string };

const rows: Row[] = [
    { id: 1, name: 'Ada' },
    { id: 2, name: 'Grace' },
];

function SortableFixture({
    initialDirection = 'asc' as 'asc' | 'desc',
}: {
    initialDirection?: 'asc' | 'desc';
}) {
    const [sortKey, setSortKey] = useState('name');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>(
        initialDirection,
    );

    return (
        <DataTable
            columns={[
                {
                    key: 'name',
                    header: 'Name',
                    sortable: true,
                    cell: (row) => row.name,
                },
            ]}
            rows={rows}
            rowKey={(row) => row.id}
            sortKey={sortKey}
            sortDirection={sortDirection}
            onSort={(key) => {
                if (key === sortKey) {
                    setSortDirection((current) =>
                        current === 'asc' ? 'desc' : 'asc',
                    );
                    return;
                }

                setSortKey(key);
                setSortDirection('asc');
            }}
            ariaLabel="People"
        />
    );
}

describe('DataTable sorting', () => {
    it('exposes aria-sort ascending when sortDirection is asc', () => {
        render(<SortableFixture initialDirection="asc" />);

        expect(
            screen.getByRole('columnheader', { name: /Name/ }),
        ).toHaveAttribute('aria-sort', 'ascending');
    });

    it('exposes aria-sort descending when sortDirection is desc', () => {
        render(<SortableFixture initialDirection="desc" />);

        expect(
            screen.getByRole('columnheader', { name: /Name/ }),
        ).toHaveAttribute('aria-sort', 'descending');
    });

    it('toggles sort direction when the column header is activated', async () => {
        const user = userEvent.setup();

        render(<SortableFixture initialDirection="asc" />);

        const header = screen.getByRole('columnheader', { name: /Name/ });
        expect(header).toHaveAttribute('aria-sort', 'ascending');

        await user.click(header);

        expect(
            screen.getByRole('columnheader', { name: /Name/ }),
        ).toHaveAttribute('aria-sort', 'descending');
    });
});
