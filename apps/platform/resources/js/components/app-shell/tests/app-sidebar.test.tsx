import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const currentFirm = { slug: 'acme', name: 'Acme Legal' };

const pageProps: Record<string, unknown> = {
    current_firm: currentFirm,
    workspaces: [currentFirm],
    permissions: {},
    auth: { user: { id: 1, name: 'Ada', is_platform_support: false } },
};
let currentUrl = '/workspaces/acme/dashboard';

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    usePage: () => mockInertiaPage(pageProps, currentUrl),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const indexStub = (segment: string) => ({
    index: (firm: { slug: string }) => ({
        url: `/workspaces/${firm.slug}/${segment}`,
    }),
});

vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/dashboard' }),
    logout: Object.assign(() => ({ url: '/logout' }), { url: () => '/logout' }),
}));
vi.mock('@/routes/workspaces', () => ({
    dashboard: Object.assign(
        (firm: { slug: string }) => ({ url: `/workspaces/${firm.slug}` }),
        { url: (firm: { slug: string }) => `/workspaces/${firm.slug}` },
    ),
}));
vi.mock('@/routes/firms', () => ({ create: { url: () => '/firms/create' } }));
vi.mock('@/routes/platform/support', () => indexStub('support'));
vi.mock('@/routes/workspaces/activity', () => indexStub('activity'));
vi.mock('@/routes/workspaces/clients', () => indexStub('clients'));
vi.mock('@/routes/workspaces/dossiers', () => indexStub('dossiers'));
vi.mock('@/routes/workspaces/media', () => indexStub('media'));
vi.mock('@/routes/workspaces/pipelines', () => indexStub('pipelines'));
vi.mock('@/routes/workspaces/templates', () => indexStub('templates'));
vi.mock('@/routes/workspaces/members', () => indexStub('members'));
vi.mock('@/routes/workspaces/roles', () => indexStub('roles'));
vi.mock('@/routes/workspaces/data-subject-requests', () =>
    indexStub('data-subject-requests'),
);
vi.mock('@/routes/workspaces/settings', () => indexStub('settings'));

vi.mock('@/components/app-shell/app-logo', () => ({
    default: () => <div>Logo</div>,
}));
vi.mock('@/components/app-shell/nav-user', () => ({
    default: () => <div>Nav user</div>,
}));
vi.mock('@/components/app-shell/workspace-switcher', () => ({
    default: () => <div>Workspace switcher</div>,
}));

// Imported lazily: a static import would pull `use-translation` into the module
// graph before `@/test/mocks` is evaluated, and the vi.mock factory would throw.
const { SidebarProvider } = await import('@/components/ui/sidebar');
const { default: AppSidebar } =
    await import('@/components/app-shell/app-sidebar');

function renderSidebar() {
    render(
        <SidebarProvider>
            <AppSidebar />
        </SidebarProvider>,
    );
}

function adminLink() {
    return screen.queryByRole('link', { name: 'Admin' });
}

describe('AppSidebar', () => {
    it('hides the admin entry without tenant.admin.access', () => {
        pageProps.permissions = { 'members.view': true, 'roles.view': true };

        renderSidebar();

        expect(adminLink()).toBeNull();
    });

    it('points the admin entry at the first section the user may reach', () => {
        pageProps.permissions = {
            'tenant.admin.access': true,
            'roles.view': true,
            'tenant.settings.manage': true,
        };

        renderSidebar();

        // members.view is absent, so Roles is the first reachable section.
        expect(adminLink()).toHaveAttribute('href', '/workspaces/acme/roles');
    });

    it('no longer lists the sections that moved into admin', () => {
        pageProps.permissions = {
            'tenant.admin.access': true,
            'members.view': true,
            'roles.view': true,
            'data_subject.view': true,
            'tenant.settings.manage': true,
        };

        renderSidebar();

        expect(adminLink()).toHaveAttribute('href', '/workspaces/acme/members');
        for (const gone of [
            'Members',
            'Roles & permissions',
            'Privacy requests',
        ]) {
            expect(screen.queryByRole('link', { name: gone })).toBeNull();
        }
        // Templates deliberately stayed a top-level destination.
        expect(
            screen.getByRole('link', { name: 'Templates' }),
        ).toBeInTheDocument();
    });

    it('keeps the admin entry active on a section it does not link to', () => {
        pageProps.permissions = {
            'tenant.admin.access': true,
            'members.view': true,
            'tenant.settings.manage': true,
        };
        currentUrl = '/workspaces/acme/settings';

        renderSidebar();

        expect(adminLink()).toHaveAttribute('aria-current', 'page');
    });
});
