import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: IndexDataCard } =
    await import('@/components/data/index-data-card');

type Row = { id: number; name: string };

const rows: Row[] = [
    { id: 1, name: 'Ada' },
    { id: 2, name: 'Grace' },
];

describe('IndexDataCard', () => {
    it('renders empty state when there are no rows', () => {
        render(
            <IndexDataCard
                title="People"
                description="0 people"
                columns={[
                    {
                        key: 'name',
                        header: 'Name',
                        cell: (row: Row) => row.name,
                    },
                ]}
                rows={[]}
                rowKey={(row) => row.id}
                emptyState={<p>Nothing here</p>}
            />,
        );

        expect(screen.getByText('Nothing here')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'People' }),
        ).toBeInTheDocument();
    });

    it('enables selection and renders bulk actions from selected rows', async () => {
        const user = userEvent.setup();

        render(
            <IndexDataCard
                title="People"
                description="2 people"
                columns={[
                    {
                        key: 'name',
                        header: 'Name',
                        cell: (row: Row) => row.name,
                    },
                ]}
                rows={rows}
                rowKey={(row) => row.id}
                emptyState={<p>Nothing here</p>}
                bulkActions={({ selectedRows, clearSelection }) =>
                    selectedRows.length === 0 ? null : (
                        <button type="button" onClick={clearSelection}>
                            Archive {selectedRows.length}
                        </button>
                    )
                }
            />,
        );

        const checkboxes = screen.getAllByRole('checkbox');
        // First checkbox is select-all; pick a row checkbox.
        const rowCheckbox = checkboxes[1];

        if (!rowCheckbox) {
            throw new Error('Expected a row checkbox after select-all');
        }

        await user.click(rowCheckbox);

        expect(
            screen.getByRole('button', { name: 'Archive 1' }),
        ).toBeInTheDocument();
    });
});
