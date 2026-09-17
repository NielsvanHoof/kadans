// @vitest-environment node
import { renderToString } from 'react-dom/server';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import type {
    PortalDossier,
    PortalDossierSummary,
} from '@/features/portal/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

/**
 * Guard render-time browser-global safety for the portal composition.
 *
 * Inertia SSR is disabled in this app, but nothing on this surface may touch a
 * browser global while rendering — an earlier revision crashed under
 * `renderToString` by reading `window.location` during render.
 *
 * This file runs in the `node` environment on purpose: no jsdom, so no
 * `window`. The page component itself cannot be imported (pages are Inertia
 * entry points, not libraries), so this renders the same composition the page
 * renders.
 */
vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@inertiajs/react', () => ({
    Form: ({ children }: { children?: unknown }) =>
        typeof children === 'function'
            ? children({ errors: {}, processing: false, submit: () => {} })
            : children,
    Link: ({ children }: { children?: unknown }) => children,
    router: { post: vi.fn() },
}));
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: PortalDocumentRequestsCard } =
    await import('@/features/document-requests/portal/portal-document-requests-card');
const { default: PortalProgressOverview } =
    await import('@/features/portal/portal-progress-overview');
const { default: PortalSessionNotice } =
    await import('@/features/portal/portal-session-notice');
const { default: PortalDossierList } =
    await import('@/features/portal/portal-dossier-list');
const { default: PortalDossierSwitcher } =
    await import('@/features/portal/portal-dossier-switcher');

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

const dossierSummaries = [
    {
        id: 7,
        title: '2025 VAT return',
        reference: 'VAT-2025',
        status: 'awaiting_client',
        due_date: null,
        remaining: 2,
        total: 5,
    },
    {
        id: 8,
        title: '2025 Annual accounts',
        reference: null,
        status: 'in_review',
        due_date: null,
        remaining: 0,
        total: 3,
    },
] as unknown as PortalDossierSummary[];

describe('portal server rendering', () => {
    it('has no window global at all', () => {
        expect('window' in globalThis).toBe(false);
    });

    it('renders the item list and the progress overview', () => {
        const html = renderToString(
            <>
                <PortalProgressOverview dossier={dossier} firmName="Acme" />
                <PortalDocumentRequestsCard
                    dossierId={7}
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

    /*
     * The landing list and the switcher both build hrefs during render. Wayfinder
     * route helpers are pure, but reaching for `location` to resolve one would be
     * the obvious wrong turn and would only show up here.
     */
    it('renders the dossier list without touching a browser global', () => {
        const html = renderToString(
            <PortalDossierList
                dossiers={dossierSummaries}
                ariaLabel="Waiting for you"
            />,
        );

        expect(html).toContain('2025 VAT return');
        expect(html).toContain('2025 Annual accounts');
    });

    it('renders the switcher, and nothing when a link opens one dossier', () => {
        expect(
            renderToString(
                <PortalDossierSwitcher otherDossiers={dossierSummaries} />,
            ),
        ).toContain('2025 VAT return');

        // A dossier-scoped link gets an empty list, and must render no chrome
        // at all — that is what keeps its page identical to before.
        expect(
            renderToString(<PortalDossierSwitcher otherDossiers={[]} />),
        ).toBe('');
    });

    /*
     * The session countdown is the newest thing on this page and the most
     * likely to reach for a browser global: it owns a timer. The timer lives in
     * an effect, which SSR never runs, but the initial seed reads the clock
     * during render — so it has to use Date.now(), not window.performance.
     */
    it('renders the session warning without touching a browser global', () => {
        const html = renderToString(
            <PortalSessionNotice
                idleExpiresAt={new Date(Date.now() + 60_000).toISOString()}
            />,
        );

        expect(html).toContain('Your secure session is about to end');
    });

    it('renders nothing for a session that is not close to ending', () => {
        const html = renderToString(
            <PortalSessionNotice
                idleExpiresAt={new Date(Date.now() + 3_600_000).toISOString()}
            />,
        );

        expect(html).toBe('');
    });
});
