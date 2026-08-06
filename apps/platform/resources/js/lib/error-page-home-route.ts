import { dashboard, login } from '@/routes';

/**
 * Shared home destination for the error page logo and CTA.
 * Keep both links on this helper so they cannot drift apart.
 */
export function errorPageHomeRoute(isAuthenticated: boolean) {
    return isAuthenticated ? dashboard() : login();
}
