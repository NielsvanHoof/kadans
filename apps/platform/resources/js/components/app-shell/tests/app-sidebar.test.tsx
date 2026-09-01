import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';
import type { TenantPermission } from '@/types/generated/tenant-permissions';

const firm = { name: 'Acme', slug: 'acme' };
let pageProps: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockInertiaPage(pageProps, '/workspaces/acme/dashboard'),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
}));

vi.mock('@/components/app-shell/workspace-switcher', () => ({
    WorkspaceSwitcher: () => null,
}));

vi.mock('@/components/app-shell/nav-user', () => ({
    NavUser: () => null,
}));

const { AppSidebar } = await import('@/components/app-shell/app-sidebar');
const { SidebarProvider } = await import('@/components/ui/sidebar');

function renderSidebar(
    permissions: Partial<Record<TenantPermission, boolean>>,
): void {
    pageProps = {
        current_firm: firm,
        auth: { user: { is_platform_support: false } },
        permissions,
    };

    render(
        <SidebarProvider>
            <AppSidebar />
        </SidebarProvider>,
    );
}

function platformNav(): HTMLElement {
    return screen.getByRole('navigation', { name: 'Platform' });
}

beforeEach(() => {
    pageProps = {};
});

describe('AppSidebar', () => {
    it('hides firm links the user cannot open', () => {
        renderSidebar({
            'dossiers.view': true,
            'templates.view': true,
            'document_requests.view': true,
        });

        const nav = platformNav();

        expect(within(nav).getByRole('link', { name: 'Dashboard' })).toBeVisible();
        expect(within(nav).getByRole('link', { name: 'Dossiers' })).toBeVisible();
        expect(within(nav).getByRole('link', { name: 'Templates' })).toBeVisible();
        expect(
            within(nav).getByRole('link', { name: 'Media Library' }),
        ).toBeVisible();
        expect(within(nav).queryByRole('link', { name: 'Clients' })).toBeNull();
        expect(within(nav).queryByRole('link', { name: 'Activity' })).toBeNull();
        expect(within(nav).queryByRole('link', { name: 'Members' })).toBeNull();
        expect(
            within(nav).queryByRole('link', { name: 'Roles & permissions' }),
        ).toBeNull();
        expect(
            within(nav).queryByRole('link', { name: 'Data-subject requests' }),
        ).toBeNull();
        expect(within(nav).queryByRole('link', { name: 'Pipelines' })).toBeNull();
    });

    it('shows Clients and Activity when those view permissions are granted', () => {
        renderSidebar({
            'dossiers.view': true,
            'clients.view': true,
            'audit.view': true,
        });

        const nav = platformNav();

        expect(within(nav).getByRole('link', { name: 'Clients' })).toBeVisible();
        expect(within(nav).getByRole('link', { name: 'Activity' })).toBeVisible();
        expect(within(nav).queryByRole('link', { name: 'Templates' })).toBeNull();
        expect(
            within(nav).queryByRole('link', { name: 'Media Library' }),
        ).toBeNull();
    });
});
