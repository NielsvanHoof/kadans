import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseTranslation } from '@/test/mocks';
import type { SimplePaginated } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        className,
        preserveState: _preserveState,
        preserveScroll: _preserveScroll,
        'aria-label': ariaLabel,
    }: {
        href: string;
        children: React.ReactNode;
        className?: string;
        preserveState?: boolean;
        preserveScroll?: boolean;
        'aria-label'?: string;
    }) => (
        <a href={href} className={className} aria-label={ariaLabel}>
            {children}
        </a>
    ),
}));

const { default: SimpleIndexPagination } =
    await import('@/components/data/index-query/simple-index-pagination');

function makePaginator(
    overrides: Partial<SimplePaginated<{ id: number }>> = {},
): SimplePaginated<{ id: number }> {
    const current = overrides.current_page ?? 1;
    const path = overrides.path ?? '/items';

    return {
        data: overrides.data ?? [{ id: 1 }],
        current_page: current,
        per_page: 15,
        from: overrides.from ?? 1,
        to: overrides.to ?? 15,
        path,
        first_page_url: `${path}?page=1`,
        next_page_url: overrides.next_page_url ?? null,
        prev_page_url: overrides.prev_page_url ?? null,
        ...overrides,
    };
}

describe('SimpleIndexPagination', () => {
    it('renders nothing when there are no results', () => {
        const { container } = render(
            <SimpleIndexPagination
                paginator={makePaginator({ data: [], from: null, to: null })}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows the range without page links on a single page', () => {
        render(
            <SimpleIndexPagination
                paginator={makePaginator({
                    from: 1,
                    to: 3,
                    next_page_url: null,
                    prev_page_url: null,
                })}
            />,
        );

        expect(screen.getByText('Showing 1–3')).toBeInTheDocument();
        expect(
            screen.queryByRole('navigation', { name: /pagination/i }),
        ).not.toBeInTheDocument();
    });

    it('links previous and next without numbered pages', () => {
        render(
            <SimpleIndexPagination
                paginator={makePaginator({
                    current_page: 2,
                    from: 16,
                    to: 30,
                    prev_page_url: '/items?page=1',
                    next_page_url: '/items?page=3',
                })}
            />,
        );

        expect(screen.getByText('Showing 16–30')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Go to previous page' }),
        ).toHaveAttribute('href', '/items?page=1');
        expect(
            screen.getByRole('link', { name: 'Go to next page' }),
        ).toHaveAttribute('href', '/items?page=3');
        expect(
            screen.queryByRole('link', { name: '2' }),
        ).not.toBeInTheDocument();
    });
});
