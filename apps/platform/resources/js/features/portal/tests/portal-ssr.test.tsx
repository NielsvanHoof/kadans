// @vitest-environment node
import { renderToString } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import type { PortalDossier } from '@/features/portal/types';
import { mockUseTranslation } from '@/test/mocks';

/**
 * The portal is server-rendered, so nothing on this page may touch a browser
 * global while rendering — an earlier revision crashed SSR by reading
 * `window.location` during render.
 *
 * This file runs in the `node` environment on purpose: no jsdom, so no
 * `window`, exactly like the Inertia SSR bundle. The page component itself
 * cannot be imported (pages are Inertia entry points, not libraries), so this
 * renders the same composition the page renders.
 */
vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children?: ((props: Record<string, unknown>) => unknown) | unknown;
    }) =>
        typeof children === 'function'
            ? children({ errors: {}, processing: false, submit: () => {} })
            : children,
    Link: ({ children }: { children?: unknown }) => children,
    router: { post: vi.fn() },
}));

const { default: PortalDocumentRequestsCard } =
    await import('@/features/document-requests/portal/portal-document-requests-card');
const { default: PortalProgressOverview } =
    await import('@/features/portal/portal-progress-overview');

const documentRequests = [
    {
        id: 1,
        title: 'Passport',
        type: 'text',
        status: 'pending',
        instructions: null,
        rejection_reason: null,
        can_respond: true,
        answer_text: null,
        answer_boolean: null,
        answer_option_ids: null,
        uploaded_documents: [],
        configuration: { is_required: true },
    },
    {
        id: 2,
        title: 'Preferred contact',
        type: 'choice',
        status: 'pending',
        instructions: 'Pick one.',
        rejection_reason: null,
        can_respond: true,
        answer_text: null,
        answer_boolean: null,
        answer_option_ids: null,
        uploaded_documents: [],
        configuration: {
            is_required: true,
            options: [
                { id: 'opt-a', label: 'By post' },
                { id: 'opt-b', label: 'By email' },
            ],
        },
    },
] as unknown as PortalDocumentRequest[];

const dossier = {
    due_date: null,
    document_requests: documentRequests,
    progress: {
        total: 2,
        completed: 0,
        remaining: 2,
        approved: 0,
        next_incomplete: { id: 1, title: 'Passport' },
    },
} as unknown as PortalDossier;

describe('portal server rendering', () => {
    it('has no window global at all', () => {
        expect('window' in globalThis).toBe(false);
    });

    it('renders the item list and the progress overview', () => {
        const html = renderToString(
            <>
                <PortalProgressOverview dossier={dossier} firmName="Acme" />
                <PortalDocumentRequestsCard
                    firmName="Acme"
                    documentRequests={documentRequests}
                    nextIncompleteRequestId={1}
                />
            </>,
        );

        expect(html).toContain('Passport');
        expect(html).toContain('Preferred contact');
        expect(html).toContain('By email');
        // The Continue affordance is an in-page anchor again, not a wizard jump.
        expect(html).toContain('#request-1');
    });
});
