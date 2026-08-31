import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { DataSubjectRequest } from '@/features/data-subject-requests/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';
import type { Paginated } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

vi.mock('@/routes/workspaces/data-subject-requests', () => ({
    download: () => ({ url: '/download' }),
    fulfil: { form: () => ({ action: '/fulfil', method: 'post' }) },
    refuse: { form: () => ({ action: '/refuse', method: 'post' }) },
}));

const { default: RequestsListCard } = await import('../requests-list-card');

/**
 * The card takes a paginator, not a flat array — see FetchDataSubjectRequestIndexQuery.
 * Same shim as features/audit/tests/activity-log-card.test.tsx.
 */
function paginated(data: DataSubjectRequest[]): Paginated<DataSubjectRequest> {
    return {
        data,
        total: data.length,
        current_page: 1,
        last_page: 1,
        per_page: 15,
        links: [],
    } as unknown as Paginated<DataSubjectRequest>;
}

/**
 * React Aria's Table is a collection: columns must sit directly under
 * TableHeader (no Row wrapper). Nesting heads in a Row collapses them to one
 * column and throws "Found N cells and 1 columns" at commit time — so a smoke
 * render with real rows is enough to catch the structural mistake.
 */
describe('RequestsListCard', () => {
    it('renders a request row without throwing a column-count error', () => {
        expect(() =>
            render(
                <RequestsListCard
                    canManage={false}
                    requests={paginated([
                        {
                            id: 1,
                            type: 'export',
                            type_label: 'Data export',
                            status: 'pending',
                            status_label: 'Pending',
                            client_id: 7,
                            client_name: 'Sander',
                            requested_at: '2026-08-01T10:00:00+00:00',
                            due_at: '2026-09-01T10:00:00+00:00',
                            is_overdue: false,
                            requested_by_name: 'Admin',
                            completed_at: null,
                            completed_by_name: null,
                            refusal_reason: null,
                            can_download: false,
                        },
                    ])}
                />,
            ),
        ).not.toThrow();

        expect(screen.getByRole('grid')).toBeTruthy();
        expect(screen.getByText('Sander')).toBeTruthy();
        expect(screen.getByText('Data export')).toBeTruthy();
    });
});
