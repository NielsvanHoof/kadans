import type { DragEndEvent } from '@dnd-kit/core';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        locale: 'en',
        availableLocales: [],
    }),
}));

type DndContextProps = {
    children: React.ReactNode;
    onDragEnd?: (event: DragEndEvent) => void | Promise<void>;
};

let latestOnDragEnd: DndContextProps['onDragEnd'];

vi.mock('@dnd-kit/core', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@dnd-kit/core')>();

    return {
        ...actual,
        DndContext: ({ children, onDragEnd }: DndContextProps) => {
            latestOnDragEnd = onDragEnd;

            return <div data-testid="dnd-context">{children}</div>;
        },
    };
});

const { default: SortableList } =
    await import('@/components/data/sortable/sortable-list');

const items = [
    { id: 1, name: 'First' },
    { id: 2, name: 'Second' },
    { id: 3, name: 'Third' },
];

describe('SortableList', () => {
    it('hides drag handles when reordering is disabled', () => {
        render(
            <SortableList
                items={items}
                enabled={false}
                onReorder={vi.fn()}
                renderItem={(item, { DragHandle }) => (
                    <div>
                        <DragHandle />
                        <span>{item.name}</span>
                    </div>
                )}
            />,
        );

        expect(screen.getByText('First')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Drag to reorder' }),
        ).not.toBeInTheDocument();
    });

    it('optimistically reorders then rolls back when onReorder returns false', async () => {
        const onReorder = vi.fn().mockResolvedValue(false);

        render(
            <SortableList
                items={items}
                onReorder={onReorder}
                renderItem={(item, { DragHandle }) => (
                    <div>
                        <DragHandle />
                        <span>{item.name}</span>
                    </div>
                )}
            />,
        );

        await waitFor(() => {
            expect(screen.getByTestId('dnd-context')).toBeInTheDocument();
        });

        await act(async () => {
            await latestOnDragEnd?.({
                active: { id: 1 },
                over: { id: 3 },
            } as DragEndEvent);
        });

        expect(onReorder).toHaveBeenCalledWith([2, 3, 1]);

        await waitFor(() => {
            const labels = screen
                .getAllByText(/First|Second|Third/)
                .map((node) => node.textContent);

            expect(labels).toEqual(['First', 'Second', 'Third']);
        });
    });

    it('keeps the new order when onReorder succeeds', async () => {
        const user = userEvent.setup();
        const onReorder = vi.fn().mockResolvedValue(true);

        const { rerender } = render(
            <SortableList
                items={items}
                onReorder={onReorder}
                renderItem={(item, { DragHandle }) => (
                    <div>
                        <DragHandle />
                        <span>{item.name}</span>
                    </div>
                )}
            />,
        );

        await waitFor(() => {
            expect(screen.getByTestId('dnd-context')).toBeInTheDocument();
        });

        await act(async () => {
            await latestOnDragEnd?.({
                active: { id: 1 },
                over: { id: 2 },
            } as DragEndEvent);
        });

        expect(onReorder).toHaveBeenCalledWith([2, 1, 3]);

        // Parent would reload props with the new order; simulate that.
        rerender(
            <SortableList
                items={[
                    { id: 2, name: 'Second' },
                    { id: 1, name: 'First' },
                    { id: 3, name: 'Third' },
                ]}
                onReorder={onReorder}
                renderItem={(item, { DragHandle }) => (
                    <div>
                        <DragHandle />
                        <span>{item.name}</span>
                    </div>
                )}
            />,
        );

        expect(
            screen.getAllByRole('button', { name: 'Drag to reorder' }),
        ).toHaveLength(3);
        // Touch one handle so userEvent is exercised (a11y presence).
        await user.click(
            screen.getAllByRole('button', { name: 'Drag to reorder' })[0],
        );
    });
});
