import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const httpPost = vi.fn();
const setData = vi.fn();
const toastError = vi.fn();

vi.mock('sonner', () => ({
    toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({
        post: httpPost,
        processing: false,
        setData,
    }),
    usePage: () =>
        mockInertiaPage({
            current_firm: { slug: 'acme', name: 'Acme' },
        }),
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
            store: {
                url: () => '/workspaces/acme/document-search',
            },
        },
    }),
);

const { default: DocumentSearchAskPanel } =
    await import('@/features/document-search/document-search-ask-panel');

beforeEach(() => {
    httpPost.mockReset();
    setData.mockReset();
    toastError.mockReset();
});

describe('DocumentSearchAskPanel', () => {
    it('posts the question and renders the answer with a citation', async () => {
        const user = userEvent.setup();
        httpPost.mockResolvedValueOnce({
            answer: 'The March salary statement is on file.',
            citations: [
                {
                    uploaded_document_id: 7,
                    why: 'Matches salary.',
                    original_filename: 'salary-march.pdf',
                    show_url: '/workspaces/acme/uploaded-documents/7',
                },
            ],
        });

        render(<DocumentSearchAskPanel onNavigate={vi.fn()} />);

        await user.type(
            screen.getByRole('textbox', { name: 'Question about documents' }),
            'Do we have a salary statement?',
        );
        await user.click(screen.getByRole('button', { name: 'Ask' }));

        await waitFor(() =>
            expect(
                screen.getByText('The March salary statement is on file.'),
            ).toBeInTheDocument(),
        );
        expect(
            screen.getByRole('link', { name: /salary-march.pdf/ }),
        ).toHaveAttribute('href', '/workspaces/acme/uploaded-documents/7');
        expect(toastError).not.toHaveBeenCalled();
    });
});
