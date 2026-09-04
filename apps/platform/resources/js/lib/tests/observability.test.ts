import { router } from '@inertiajs/react';
import * as Sentry from '@sentry/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    initializeObservability,
    redactBreadcrumb,
    redactEvent,
    redactPortalTokens,
    stampPageContext,
} from '@/lib/observability';

vi.mock('@sentry/react', () => ({
    init: vi.fn(),
    setTag: vi.fn(),
    setUser: vi.fn(),
    browserTracingIntegration: vi.fn(() => ({ name: 'BrowserTracing' })),
}));

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn() },
}));

const TOKEN = 'testPortalToken123456';

describe('redactPortalTokens', () => {
    it('strips a portal grant token from a path', () => {
        expect(redactPortalTokens(`/portal/access/${TOKEN}`)).toBe(
            '/portal/access/[redacted]',
        );
    });

    it('strips it from an absolute url and keeps the rest intact', () => {
        expect(
            redactPortalTokens(
                `https://app.qompose.test/portal/access/${TOKEN}?foo=1`,
            ),
        ).toBe('https://app.qompose.test/portal/access/[redacted]?foo=1');
    });

    it('strips every occurrence, not just the first', () => {
        expect(
            redactPortalTokens(
                `/portal/access/${TOKEN} then /portal/access/abc123`,
            ),
        ).toBe('/portal/access/[redacted] then /portal/access/[redacted]');
    });

    it('leaves unrelated portal urls alone', () => {
        expect(redactPortalTokens('/portal/document-requests/12/upload')).toBe(
            '/portal/document-requests/12/upload',
        );
    });
});

describe('redactEvent', () => {
    it('redacts the request url, the transaction name, and breadcrumbs', () => {
        const event = redactEvent<Sentry.Event>({
            request: { url: `https://app.qompose.test/portal/access/${TOKEN}` },
            transaction: `/portal/access/${TOKEN}`,
            breadcrumbs: [
                {
                    category: 'navigation',
                    data: { from: `/portal/access/${TOKEN}`, to: '/portal' },
                },
            ],
        });

        expect(event.request?.url).toBe(
            'https://app.qompose.test/portal/access/[redacted]',
        );
        expect(event.transaction).toBe('/portal/access/[redacted]');
        expect(event.breadcrumbs?.[0]?.data).toEqual({
            from: '/portal/access/[redacted]',
            to: '/portal',
        });
    });

    it('redacts a referer header, which carries the url of the previous page', () => {
        const event = redactEvent<Sentry.Event>({
            request: { headers: { Referer: `/portal/access/${TOKEN}` } },
        });

        expect(event.request?.headers?.Referer).toBe(
            '/portal/access/[redacted]',
        );
    });

    it('leaves an event with nothing to redact untouched', () => {
        const event = redactEvent<Sentry.Event>({
            transaction: '/dossiers/12',
        });

        expect(event.transaction).toBe('/dossiers/12');
        expect(event.request).toBeUndefined();
    });
});

describe('redactBreadcrumb', () => {
    it('redacts the message as well as the data', () => {
        expect(
            redactBreadcrumb({
                message: `navigated to /portal/access/${TOKEN}`,
                data: { url: `/portal/access/${TOKEN}`, status_code: 500 },
            }),
        ).toEqual({
            message: 'navigated to /portal/access/[redacted]',
            data: { url: '/portal/access/[redacted]', status_code: 500 },
        });
    });

    it('preserves non-string data values', () => {
        expect(
            redactBreadcrumb({ data: { status_code: 200, ok: true } }).data,
        ).toEqual({ status_code: 200, ok: true });
    });
});

describe('stampPageContext', () => {
    beforeEach(() => {
        vi.mocked(Sentry.setUser).mockClear();
        vi.mocked(Sentry.setTag).mockClear();
    });

    it('identifies the user by id and nothing else', () => {
        stampPageContext('workspaces/dossiers/show', {
            auth: { user: { id: 42 } },
            current_firm: { slug: 'acme' },
        });

        // Never name, email, or ip — the backend stamps an id only for the same
        // reason, and a page prop carrying more must not widen that.
        expect(Sentry.setUser).toHaveBeenCalledWith({ id: 42 });
    });

    it('clears the user on sign-out so events are not misattributed', () => {
        stampPageContext('auth/login', { auth: { user: null } });

        expect(Sentry.setUser).toHaveBeenCalledWith(null);
    });

    it('tags the firm and the page component', () => {
        stampPageContext('workspaces/dossiers/show', {
            auth: { user: { id: 42 } },
            current_firm: { slug: 'acme' },
        });

        expect(Sentry.setTag).toHaveBeenCalledWith('workspace', 'acme');
        expect(Sentry.setTag).toHaveBeenCalledWith(
            'inertia_component',
            'workspaces/dossiers/show',
        );
    });

    it('leaves the workspace untagged on portal pages, which have no slug', () => {
        // WorkspaceProps sends the firm name but omits the slug on portal
        // routes, so there is nothing here to tag the event with.
        stampPageContext('portal/show', { current_firm: {} });

        expect(Sentry.setTag).toHaveBeenCalledWith('workspace', undefined);
    });
});

describe('initializeObservability', () => {
    beforeEach(() => {
        vi.mocked(Sentry.init).mockClear();
        vi.mocked(router.on).mockClear();
    });

    it('does not report from a dev build even when a dsn is configured', () => {
        vi.stubEnv('VITE_SENTRY_DSN', 'https://public@o1.ingest.sentry.io/2');
        vi.stubEnv('DEV', true);

        initializeObservability();

        expect(Sentry.init).not.toHaveBeenCalled();
    });

    it('stays silent when the bundle was built without a dsn', () => {
        vi.stubEnv('VITE_SENTRY_DSN', '');
        vi.stubEnv('DEV', false);

        initializeObservability();

        expect(Sentry.init).not.toHaveBeenCalled();
    });

    it('initializes with pii collection disabled when a dsn is present', () => {
        vi.stubEnv('VITE_SENTRY_DSN', 'https://public@o1.ingest.sentry.io/2');
        vi.stubEnv('DEV', false);

        initializeObservability();

        expect(Sentry.init).toHaveBeenCalledWith(
            expect.objectContaining({
                dsn: 'https://public@o1.ingest.sentry.io/2',
                dataCollection: expect.objectContaining({
                    userInfo: false,
                    cookies: false,
                    httpBodies: [],
                }),
                beforeSend: redactEvent,
                beforeSendTransaction: redactEvent,
                beforeBreadcrumb: redactBreadcrumb,
            }),
        );
    });

    it('disables tracing when no sample rate is configured', () => {
        vi.stubEnv('VITE_SENTRY_DSN', 'https://public@o1.ingest.sentry.io/2');
        vi.stubEnv('DEV', false);
        vi.stubEnv('VITE_SENTRY_TRACES_SAMPLE_RATE', '');

        initializeObservability();

        expect(
            vi.mocked(Sentry.init).mock.calls[0]?.[0]?.tracesSampleRate,
        ).toBeUndefined();
    });

    /**
     * Inertia fires `navigate` on the initial page load as well as every visit,
     * so this one listener is the only thing keeping user and firm current
     * across a workspace switch or a sign-out.
     */
    it('re-stamps context on every inertia navigation', () => {
        vi.stubEnv('VITE_SENTRY_DSN', 'https://public@o1.ingest.sentry.io/2');
        vi.stubEnv('DEV', false);
        vi.mocked(Sentry.setTag).mockClear();

        initializeObservability();

        expect(router.on).toHaveBeenCalledWith(
            'navigate',
            expect.any(Function),
        );

        const onNavigate = vi.mocked(router.on).mock.calls[0]?.[1];

        onNavigate?.({
            detail: {
                page: {
                    component: 'workspaces/dossiers/show',
                    props: {
                        auth: { user: { id: 7 } },
                        current_firm: { slug: 'acme' },
                    },
                },
            },
        } as Parameters<typeof onNavigate>[0]);

        expect(Sentry.setUser).toHaveBeenCalledWith({ id: 7 });
        expect(Sentry.setTag).toHaveBeenCalledWith('workspace', 'acme');
        expect(Sentry.setTag).toHaveBeenCalledWith(
            'inertia_component',
            'workspaces/dossiers/show',
        );
    });

    it('reads the sample rate as a number', () => {
        vi.stubEnv('VITE_SENTRY_DSN', 'https://public@o1.ingest.sentry.io/2');
        vi.stubEnv('DEV', false);
        vi.stubEnv('VITE_SENTRY_TRACES_SAMPLE_RATE', '0.2');

        initializeObservability();

        expect(
            vi.mocked(Sentry.init).mock.calls[0]?.[0]?.tracesSampleRate,
        ).toBe(0.2);
    });
});
