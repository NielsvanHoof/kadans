// @vitest-environment node
import { renderToString } from 'react-dom/server';
import { describe, expect, it, vi } from 'vite-plus/test';
import type {
    DashboardFollowUpSignals,
    DashboardMyWork,
    DashboardProcessingHealth,
} from '@/features/dashboard/types';
import { mockUseTranslation } from '@/test/mocks';

/**
 * Guard render-time browser-global safety for dashboard cards.
 *
 * Inertia SSR is disabled in this app, but these cards must still survive
 * `renderToString` (no `window`) so they stay safe if SSR is ever re-enabled
 * or if a deferred prop resolves on the server later.
 *
 * This file runs in the `node` environment on purpose: no jsdom, so no
 * `window`. Pages are Inertia entry points rather than libraries, so this
 * renders the same cards the page composes.
 *
 * The deferred cards are the ones worth pinning. They currently escape server
 * rendering because their props resolve after paint — this test makes sure they
 * would still survive if that ever changed, rather than relying on it.
 */
vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { current_firm: { slug: 'acme', name: 'Acme' } },
    }),
    Link: ({ children }: { children?: unknown }) => children,
}));

const { default: MyWorkCard } =
    await import('@/features/dashboard/my-work-card');
const { default: FollowUpSignalsCard } =
    await import('@/features/dashboard/follow-up-signals-card');
const { default: ProcessingHealthCard } =
    await import('@/features/dashboard/processing-health-card');

const myWork: DashboardMyWork = {
    assigned_open: 2,
    due_this_week: 1,
    overdue: 1,
    awaiting_my_review: 0,
    agenda: [
        {
            id: 1,
            title: 'Annual audit',
            client_name: 'Acme Corp',
            status: 'awaiting_client',
            due_date: '2026-09-01',
            days_remaining: -2,
        },
    ],
};

const signals: DashboardFollowUpSignals = {
    never_opened: [
        {
            id: 1,
            title: 'Annual audit',
            client_name: 'Acme Corp',
            since: '2026-08-01T00:00:00+00:00',
            days: 9,
        },
    ],
    gone_quiet: [],
    expiring_links: [],
    total_count: 1,
};

const health: DashboardProcessingHealth = {
    failed: 1,
    stuck: 0,
    recent: [
        {
            id: 7,
            type: 'ocr_document',
            status: 'failed',
            error_message: 'Provider returned 500',
            last_activity_at: '2026-08-17T10:00:00+00:00',
            is_retryable: true,
            subject: {
                type: 'UploadedDocument',
                id: 12,
                label: 'annual-accounts.pdf',
                context: 'Acme Corp · Annual audit',
                href: null,
            },
        },
    ],
};

describe('dashboard server rendering', () => {
    it('runs without a window global', () => {
        expect('window' in globalThis).toBe(false);
    });

    it('renders the personal queue on the server', () => {
        expect(() =>
            renderToString(<MyWorkCard myWork={myWork} />),
        ).not.toThrow();
    });

    it('renders client follow-up signals on the server', () => {
        expect(() =>
            renderToString(<FollowUpSignalsCard signals={signals} />),
        ).not.toThrow();
    });

    it('renders processing health on the server', () => {
        expect(() =>
            renderToString(<ProcessingHealthCard health={health} />),
        ).not.toThrow();
    });
});
