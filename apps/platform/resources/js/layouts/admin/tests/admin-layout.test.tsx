import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const currentFirm = { slug: 'acme', name: 'Acme Legal' };

const pageProps: Record<string, unknown> = {
    current_firm: currentFirm,
    permissions: {},
};
let currentUrl = '/workspaces/acme/settings';

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockInertiaPage(pageProps, currentUrl),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const routeStub = (segment: string) => ({
    index: (firm: { slug: string }) => ({
        url: `/workspaces/${firm.slug}/${segment}`,
    }),
});

vi.mock('@/routes/workspaces/members', () => routeStub('members'));
vi.mock('@/routes/workspaces/roles', () => routeStub('roles'));
vi.mock('@/routes/workspaces/data-subject-requests', () =>
    routeStub('data-subject-requests'),
);
vi.mock('@/routes/workspaces/settings', () => routeStub('settings'));

// Imported lazily: a static import would pull `use-translation` into the module
// graph before `@/test/mocks` is evaluated, and the vi.mock factory would throw.
const { default: AdminLayout } = await import('@/layouts/admin/admin-layout');

const OWNER = {
    'members.view': true,
    'roles.view': true,
    'data_subject.view': true,
    'tenant.settings.manage': true,
};

function renderLayout() {
    render(
        <AdminLayout>
            <p>Section content</p>
        </AdminLayout>,
    );
}

function railItems() {
    return screen.getAllByRole('link').map((link) => link.textContent);
}

describe('AdminLayout', () => {
    it('lists every section an owner may reach', () => {
        pageProps.permissions = OWNER;

        renderLayout();

        expect(railItems()).toEqual([
            'Members',
            'Roles & permissions',
            'Privacy requests',
            'Firm settings',
        ]);
        expect(
            screen.getByRole('heading', { level: 1, name: 'Admin' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Section content')).toBeInTheDocument();
    });

    it('hides data-subject requests from an administrator', () => {
        pageProps.permissions = { ...OWNER, 'data_subject.view': false };

        renderLayout();

        expect(railItems()).toEqual([
            'Members',
            'Roles & permissions',
            'Firm settings',
        ]);
    });

    it('marks only the current section active', () => {
        pageProps.permissions = OWNER;
        currentUrl = '/workspaces/acme/roles';

        renderLayout();

        // Whole-token check: the ghost variant already ships `hover:bg-muted`,
        // so a substring match would pass for an inactive item too.
        const active = screen.getByRole('link', {
            name: 'Roles & permissions',
        });
        const inactive = screen.getByRole('link', { name: 'Members' });

        expect(active.classList.contains('bg-muted')).toBe(true);
        expect(inactive.classList.contains('bg-muted')).toBe(false);
    });

    it('renders children bare when no section is reachable', () => {
        pageProps.permissions = {};
        currentUrl = '/workspaces/acme/settings';

        renderLayout();

        expect(screen.queryByRole('link')).toBeNull();
        expect(
            screen.queryByRole('heading', { level: 1, name: 'Admin' }),
        ).toBeNull();
        expect(screen.getByText('Section content')).toBeInTheDocument();
    });
});
