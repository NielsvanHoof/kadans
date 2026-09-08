import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const httpGet = vi.fn();
const httpPost = vi.fn();
let pageProps: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({
        get: httpGet,
        post: httpPost,
        processing: false,
        setData: vi.fn(),
    }),
    usePage: () => mockInertiaPage(pageProps),
    Link: ({
        href,
        children,
        onClick,
    }: {
        href: string;
        children: ReactNode;
        onClick?: () => void;
    }) => (
        <a href={href} onClick={onClick}>
            {children}
        </a>
    ),
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock(
    '@/actions/App/Reporting/Http/Controllers/DocumentSearch/DocumentSearchController',
    () => ({
        default: {
            index: {
                url: () => '/workspaces/acme/document-search',
            },
            store: {
                url: () => '/workspaces/acme/document-search',
            },
        },
    }),
);

const { default: DocumentSearchPalette } =
    await import('@/features/document-search/document-search-palette');

function workspacePage(documentSearchEnabled = true): Record<string, unknown> {
    return {
        current_firm: { slug: 'acme', name: 'Acme' },
        firm_settings: {
            ai: {
                document_overview: true,
                checklist_draft: true,
                reminder_draft: true,
                document_search: documentSearchEnabled,
            },
        },
    };
}

beforeEach(() => {
    httpGet.mockReset();
    httpPost.mockReset();
    pageProps = workspacePage();
});

describe('DocumentSearchPalette', () => {
    it('searches after typing and lists a hit', async () => {
        const user = userEvent.setup();
        httpGet.mockResolvedValue({
            hits: [
                {
                    id: 7,
                    original_filename: 'salary-march.pdf',
                    document_type: 'bank_statement',
                    summary: 'Monthly salary payments for March.',
                    request_title: 'Bank statement',
                    dossier_id: 3,
                    dossier_title: 'Payroll 2026',
                    client_name: 'Acme BV',
                    show_url: '/workspaces/acme/uploaded-documents/7',
                },
            ],
            truncated: false,
        });

        render(<DocumentSearchPalette />);

        await user.click(
            screen.getByRole('button', { name: 'Search documents' }),
        );
        await user.type(
            screen.getByRole('searchbox', { name: 'Search documents' }),
            'salary',
        );

        await waitFor(() =>
            expect(
                screen.getByRole('link', { name: /salary-march.pdf/ }),
            ).toBeInTheDocument(),
        );
        expect(httpGet).toHaveBeenCalled();
    });

    it('hides the ask tab when the firm opted out of document search', async () => {
        const user = userEvent.setup();
        pageProps = workspacePage(false);

        render(<DocumentSearchPalette />);

        await user.click(
            screen.getByRole('button', { name: 'Search documents' }),
        );

        expect(screen.getByRole('tab', { name: 'Search' })).toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: 'Ask' })).toBeNull();
    });
});
