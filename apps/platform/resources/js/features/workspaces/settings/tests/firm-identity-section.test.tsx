import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock(
    '@/actions/App/Tenancy/Http/Controllers/WorkspaceSettingsController',
    () => ({
        default: {
            updateFirm: {
                form: () => ({
                    action: '/workspaces/acme/settings/firm',
                    method: 'patch',
                }),
            },
        },
    }),
);

const submitted: Record<string, FormDataEntryValue>[] = [];

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        // Inertia-only — must not leak onto the DOM <form>.
        options: _options,
        action,
        method,
    }: {
        children: (state: {
            processing: boolean;
            errors: Record<string, string>;
        }) => React.ReactNode;
        options?: unknown;
        action?: string;
        method?: string;
    }) => (
        <form
            action={action}
            method={method}
            onSubmit={(event) => {
                event.preventDefault();
                submitted.push(
                    Object.fromEntries(
                        new FormData(event.currentTarget).entries(),
                    ),
                );
            }}
        >
            {children({ processing: false, errors: {} })}
        </form>
    ),
}));

const { default: FirmIdentitySection } =
    await import('@/features/workspaces/settings/firm-identity-section');

describe('FirmIdentitySection', () => {
    it('prefills the firm’s current name and address', () => {
        render(<FirmIdentitySection firm={{ name: 'Acme', slug: 'acme' }} />);

        expect(
            (screen.getByLabelText('Firm name') as HTMLInputElement).value,
        ).toBe('Acme');
        expect(
            (screen.getByLabelText('Web address') as HTMLInputElement).value,
        ).toBe('acme');
    });

    it('warns that changing the address breaks links people have saved', () => {
        render(<FirmIdentitySection firm={{ name: 'Acme', slug: 'acme' }} />);

        expect(screen.getByText(/breaks saved links/i)).toBeInTheDocument();
    });

    it('submits both the name and the address', async () => {
        submitted.length = 0;
        const user = userEvent.setup();

        render(<FirmIdentitySection firm={{ name: 'Acme', slug: 'acme' }} />);

        await user.clear(screen.getByLabelText('Firm name'));
        await user.type(screen.getByLabelText('Firm name'), 'Beta Advisory');
        await user.clear(screen.getByLabelText('Web address'));
        await user.type(screen.getByLabelText('Web address'), 'beta-advisory');
        await user.click(
            screen.getByRole('button', { name: 'Save firm details' }),
        );

        expect(submitted).toEqual([
            { name: 'Beta Advisory', slug: 'beta-advisory' },
        ]);
    });
});
