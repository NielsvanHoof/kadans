import { createInertiaApp, router } from '@inertiajs/react';
import { RouterProvider } from 'react-aria-components';
import AriaLocaleProvider from '@/components/app-shell/aria-locale-provider';
import { Toaster } from '@/components/ui/sonner';
import { initializeTheme } from '@/hooks/use-appearance';
import AdminLayout from '@/layouts/admin/admin-layout';
import AppLayout from '@/layouts/app/app-layout';
import AuthLayout from '@/layouts/auth/auth-layout';
import DossierBuilderLayout from '@/layouts/dossiers/dossier-builder-layout';
import OnboardingLayout from '@/layouts/onboarding/onboarding-layout';
import SettingsLayout from '@/layouts/settings/settings-layout';
import { initializeObservability } from '@/lib/observability';

// Before anything else, so a failure in the setup below is itself reported.
// No-ops on a dev build or when the bundle carries no DSN.
initializeObservability();

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Page chrome, before the locale provider is prepended.
 * `null` means the page renders its own shell (portal, error).
 */
function pageLayout(name: string) {
    switch (true) {
        case name === 'error':
            // Standalone HTTP errors — works for guests and signed-in users.
            return null;
        case name.startsWith('portal/'):
            // Guest magic-link portal — no staff chrome.
            return null;
        case name.startsWith('legal/'):
            // Public privacy / cookies / terms — own shell, no staff chrome.
            return null;
        case name === 'dossiers/builder':
            // Full-bleed form builder without the app sidebar.
            return DossierBuilderLayout;
        case name.startsWith('auth/'):
        case name.startsWith('invitations/'):
            return AuthLayout;
        case name.startsWith('onboarding/'):
            // First-run firm setup — brand shell without the staff sidebar.
            return OnboardingLayout;
        case name.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        case name === 'workspaces/members/index':
        case name === 'workspaces/roles/index':
        case name === 'data-subject-requests/index':
        case name === 'workspaces/settings/index':
            // Firm administration — one rail across the section landing pages.
            return [AppLayout, AdminLayout];
        default:
            return AppLayout;
    }
}

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    /*
     * AriaLocaleProvider is prepended to every page's layout stack rather than
     * living in `withApp`, because it reads the locale from Inertia page props
     * — and `withApp` wraps *outside* the Inertia context, where `usePage`
     * throws. Doing it here also means the locale follows a `router.reload()`
     * from the language switcher, which the `<html lang>` attribute would not.
     */
    layout: (name) => {
        const layout = pageLayout(name);

        if (layout === null) {
            return AriaLocaleProvider;
        }

        return [
            AriaLocaleProvider,
            ...(Array.isArray(layout) ? layout : [layout]),
        ];
    },
    strictMode: true,
    withApp(app) {
        return (
            /*
             * React Aria's Link and any component given an `href` render a real
             * anchor and navigate natively — which would turn every in-app link
             * into a full page load. RouterProvider hands that navigation to
             * Inertia instead, so `href` stays a client-side visit.
             *
             * Exception: file downloads that 302 to a signed S3 URL, and OAuth
             * redirects to an IdP, must use a plain <a>, not LinkButton. An
             * Inertia visit follows the redirect as XHR — S3 fails CORS (PUT
             * only); Microsoft is blocked by connect-src CSP.
             */
            <RouterProvider
                navigate={(href, options) => router.visit(href, options)}
            >
                {app}
                <Toaster richColors />
            </RouterProvider>
        );
    },
    progress: {
        // Coral — matches --brand in app.css. Inertia takes a static colour, so
        // this cannot read the token; keep the two in step by hand.
        color: '#EF5734',
    },
});

// This will set light / dark mode on load...
initializeTheme();
