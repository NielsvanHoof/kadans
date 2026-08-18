import { router } from '@inertiajs/react';
import * as Sentry from '@sentry/react';

/**
 * Browser error reporting.
 *
 * The posture mirrors config/sentry.php as closely as the two SDKs allow:
 * nothing is reported from a dev build, no PII beyond a pseudonymous user id,
 * and client portal grant tokens never leave the page. Only the transport
 * differs — the browser bundle carries its own DSN and reports to its own
 * project, so a minified JS error cannot drown out a PHP exception.
 *
 * @see app/Platform/Support/Sentry/RedactsPortalTokensFromEvents.php
 * @see app/Platform/Support/Sentry/StampsEventsWithObservabilityContext.php
 */

/**
 * Client portal magic links carry their grant token in the URL path, so
 * `window.location` holds a live credential for the whole portal session — one
 * that stays valid until the grant expires, up to 90 days later. The token
 * reaches Sentry through three separate channels (the event's request URL, the
 * pageload transaction name, and every navigation or fetch breadcrumb derived
 * from the URL), so all three are rewritten.
 *
 * The character class matches the route constraint in routes/portal.php; keep
 * this and RedactsPortalTokensFromEvents::TokenPathPattern in step with it.
 */
const PortalTokenPattern = /(\/portal\/access\/)[A-Za-z0-9]+/g;

/**
 * Traces are only propagated to this origin. The default already excludes
 * cross-origin requests, but being explicit matters here: document uploads and
 * downloads go straight to S3 on presigned URLs, and attaching `sentry-trace`
 * and `baggage` headers to those would add headers the signature does not
 * cover and trip a CORS preflight the storage host will not answer.
 */
const SameOriginOnly = [/^\//];

export function redactPortalTokens(value: string): string {
    return value.replace(PortalTokenPattern, '$1[redacted]');
}

/**
 * Rewrite every field of an outgoing event that can hold a portal URL.
 *
 * Used for error *and* transaction events: the pageload transaction is named
 * after the URL, so a portal visit would otherwise ship the token as a
 * transaction name even when nothing went wrong.
 */
export function redactEvent<T extends Sentry.Event>(event: T): T {
    if (typeof event.request?.url === 'string') {
        event.request.url = redactPortalTokens(event.request.url);
    }

    if (event.request?.headers) {
        event.request.headers = redactStringValues(event.request.headers);
    }

    if (typeof event.transaction === 'string') {
        event.transaction = redactPortalTokens(event.transaction);
    }

    if (event.breadcrumbs) {
        event.breadcrumbs = event.breadcrumbs.map(redactBreadcrumb);
    }

    return event;
}

/**
 * Breadcrumbs put the URL in different places per type — `data.to`/`data.from`
 * for navigation, `data.url` for fetch and xhr, the message itself for others —
 * so every string is rewritten rather than a fixed list of keys.
 */
export function redactBreadcrumb(
    breadcrumb: Sentry.Breadcrumb,
): Sentry.Breadcrumb {
    return {
        ...breadcrumb,
        ...(typeof breadcrumb.message === 'string'
            ? { message: redactPortalTokens(breadcrumb.message) }
            : {}),
        ...(breadcrumb.data
            ? { data: redactStringValues(breadcrumb.data) }
            : {}),
    };
}

/**
 * Start reporting, unless this build has no business doing so.
 *
 * `import.meta.env.DEV` is the frontend's equivalent of the `APP_ENV` guard in
 * config/sentry.php: the dev server never reports, even if a DSN is present in
 * the developer's .env. Vite inlines these values at build time, so a
 * production bundle built without a DSN stays silent too.
 */
export function initializeObservability(): void {
    const dsn = import.meta.env.VITE_SENTRY_DSN;

    if (import.meta.env.DEV || !dsn) {
        return;
    }

    Sentry.init({
        dsn,
        environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || undefined,
        // Set per deploy to the released image tag, so browser errors group by
        // version and uploaded source maps resolve against the right bundle.
        release: import.meta.env.VITE_SENTRY_RELEASE || undefined,
        integrations: [Sentry.browserTracingIntegration()],
        tracesSampleRate: sampleRate(
            import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE,
        ),
        tracePropagationTargets: SameOriginOnly,
        /*
         * The v10 replacement for `send_default_pii`, which the PHP side still
         * uses. Every category here is off by default in the SDK *except* the
         * ones that matter most to us, hence spelling them all out:
         *
         *  - `userInfo` would attach the client IP; stampPageContext sets a
         *    pseudonymous id instead, which is enough to tell one affected user
         *    from many.
         *  - Cookies would carry the session and portal grant cookies.
         *  - Request and response bodies are dossier content and answers.
         *
         * Query strings stay on: list filters are how a page-level bug is
         * reproduced, and the SDK strips values that look like secrets.
         */
        dataCollection: {
            userInfo: false,
            cookies: false,
            httpHeaders: { request: false, response: false },
            httpBodies: [],
            urlQueryParams: true,
        },
        beforeSend: redactEvent,
        beforeSendTransaction: redactEvent,
        beforeBreadcrumb: redactBreadcrumb,
    });

    router.on('navigate', (event) => {
        stampPageContext(event.detail.page.component, event.detail.page.props);
    });
}

/**
 * Attribute events to a firm, a user, and a page.
 *
 * The equivalent of StampsEventsWithObservabilityContext on the backend, and
 * for the same reason: an error tells you what broke but not for whom, which is
 * useless when the question is whether one firm is affected or all of them.
 *
 * Read from Inertia page props on every visit rather than once at boot, because
 * both change without a page load — the firm on a workspace switch, the user on
 * sign-in or sign-out.
 */
export function stampPageContext(
    component: string,
    props: {
        auth?: { user?: { id: number } | null };
        current_firm?: { slug?: string } | null;
    },
): void {
    const userId = props.auth?.user?.id;

    // The id only — never name or email. Sentry keeps the last user set on the
    // scope, so signing out has to clear it explicitly.
    Sentry.setUser(userId === undefined ? null : { id: userId });

    /*
     * The slug, because no tenant id reaches the frontend. Portal pages
     * deliberately omit it (WorkspaceProps treats the slug as staff-only
     * enumeration material), so portal events are tagged by component alone.
     */
    Sentry.setTag('workspace', props.current_firm?.slug);

    // The page component, not the URL: it groups by screen without dragging
    // dossier ids or portal tokens into the tag value.
    Sentry.setTag('inertia_component', component);
}

/**
 * An unset or unparseable rate disables tracing, matching the `null` default
 * in config/sentry.php rather than silently sampling everything.
 */
function sampleRate(value: string | undefined): number | undefined {
    if (value === undefined || value === '') {
        return undefined;
    }

    const rate = Number(value);

    return Number.isFinite(rate) ? rate : undefined;
}

function redactStringValues<T extends Record<string, unknown>>(values: T): T {
    return Object.fromEntries(
        Object.entries(values).map(([key, value]) => [
            key,
            typeof value === 'string' ? redactPortalTokens(value) : value,
        ]),
    ) as T;
}
