import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string, replacements?: Record<string, string | number>) => {
            if (!replacements) {
                return key;
            }

            return Object.entries(replacements).reduce(
                (result, [token, value]) =>
                    result.replace(`:${token}`, String(value)),
                key,
            );
        },
        locale: 'en',
        availableLocales: [],
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
        className?: string;
        'aria-label'?: string;
        'aria-current'?: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

const { default: IndexPagination } =
    await import('@/components/data/index-query/index-pagination');

function makePaginator(
    overrides: Partial<Paginated<{ id: number }>> = {},
): Paginated<{ id: number }> {
    const current = overrides.current_page ?? 1;
    const last = overrides.last_page ?? 1;
    const path = overrides.path ?? '/items';

    const numericLinks = Array.from({ length: last }, (_, index) => {
        const page = index + 1;

        return {
            url: `${path}?page=${page}`,
            label: String(page),
            active: page === current,
        };
    });

    return {
        data: [],
        current_page: current,
        last_page: last,
        per_page: 15,
        total: overrides.total ?? 30,
        from: overrides.from ?? 1,
        to: overrides.to ?? 15,
        path,
        first_page_url: `${path}?page=1`,
        last_page_url: `${path}?page=${last}`,
        next_page_url:
            overrides.next_page_url ??
            (current < last ? `${path}?page=${current + 1}` : null),
        prev_page_url:
            overrides.prev_page_url ??
            (current > 1 ? `${path}?page=${current - 1}` : null),
        links: [
            {
                url: current > 1 ? `${path}?page=${current - 1}` : null,
                label: '&laquo; Previous',
                active: false,
            },
            ...numericLinks,
            {
                url: current < last ? `${path}?page=${current + 1}` : null,
                label: 'Next &raquo;',
                active: false,
            },
        ],
        ...overrides,
    };
}

describe('IndexPagination', () => {
    it('renders nothing when there are no results', () => {
        const { container } = render(
            <IndexPagination paginator={makePaginator({ total: 0 })} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows the result range without page links on a single page', () => {
        render(
            <IndexPagination
                paginator={makePaginator({
                    total: 3,
                    from: 1,
                    to: 3,
                    last_page: 1,
                })}
            />,
        );

        expect(screen.getByText('Showing 1–3 of 3')).toBeInTheDocument();
        expect(
            screen.queryByRole('navigation', { name: /pagination/i }),
        ).not.toBeInTheDocument();
    });

    it('links previous, next, and page numbers across pages', () => {
        render(
            <IndexPagination
                paginator={makePaginator({
                    current_page: 2,
                    last_page: 3,
                    total: 45,
                    from: 16,
                    to: 30,
                })}
            />,
        );

        expect(screen.getByText('Showing 16–30 of 45')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Go to previous page' }),
        ).toHaveAttribute('href', '/items?page=1');
        expect(
            screen.getByRole('link', { name: 'Go to next page' }),
        ).toHaveAttribute('href', '/items?page=3');
        expect(screen.getByRole('link', { name: '2' })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('uses an ellipsis window when there are many pages', () => {
        render(
            <IndexPagination
                paginator={makePaginator({
                    current_page: 5,
                    last_page: 12,
                    total: 180,
                    from: 61,
                    to: 75,
                })}
            />,
        );

        expect(screen.getByRole('link', { name: '1' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: '12' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: '4' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: '5' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: '6' })).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: '3' }),
        ).not.toBeInTheDocument();
        expect(screen.getAllByText('More pages')).toHaveLength(2);
    });
});
