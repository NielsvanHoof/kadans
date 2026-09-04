import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const currentFirm = { slug: 'acme', name: 'Acme Legal' };

const pageProps: Record<string, unknown> = {
    current_firm: currentFirm,
    workspaces: [currentFirm],
    permissions: {},
};

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    usePage: () => mockInertiaPage(pageProps),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/routes/firms', () => ({
    create: { url: () => '/firms/create' },
}));

vi.mock('@/routes/workspaces', () => ({
    dashboard: { url: (firm: { slug: string }) => `/workspaces/${firm.slug}` },
}));

vi.mock('@/routes/workspaces/settings', () => ({
    index: (firm: { slug: string }) => ({
        url: `/workspaces/${firm.slug}/settings`,
    }),
}));

// Imported lazily: a static import would pull `use-translation` into the module
// graph before `@/test/mocks` is evaluated, and the vi.mock factory would throw.
const { SidebarProvider } = await import('@/components/ui/sidebar');
const { default: WorkspaceSwitcher } =
    await import('@/components/app-shell/workspace-switcher');

async function openSwitcher() {
    const user = userEvent.setup();

    render(
        <SidebarProvider>
            <WorkspaceSwitcher />
        </SidebarProvider>,
    );

    await user.click(screen.getByRole('button', { name: /Acme Legal/ }));
}

describe('WorkspaceSwitcher', () => {
    it('links to firm settings when the firm settings permission is granted', async () => {
        pageProps.permissions = { 'tenant.settings.manage': true };

        await openSwitcher();

        expect(
            screen.getByRole('menuitem', { name: 'Firm settings' }),
        ).toHaveAttribute('href', '/workspaces/acme/settings');
    });

    it('hides firm settings without the permission', async () => {
        pageProps.permissions = {};

        await openSwitcher();

        expect(
            screen.queryByRole('menuitem', { name: 'Firm settings' }),
        ).toBeNull();
        expect(
            screen.getByRole('menuitem', { name: 'Create new firm' }),
        ).toBeInTheDocument();
    });
});
