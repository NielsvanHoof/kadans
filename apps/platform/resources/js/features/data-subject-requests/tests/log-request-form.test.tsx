import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@/routes/workspaces/data-subject-requests', () => ({
    store: { form: () => ({ action: '/x', method: 'post' }) },
}));

const submitted: Record<string, FormDataEntryValue>[] = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            clients: [
                { id: 7, name: 'Sander', email: 'sander@example.com' },
                { id: 9, name: 'Marieke', email: 'marieke@example.com' },
            ],
        },
    }),
    Form: ({
        children,
        // Inertia-only — must not leak onto the DOM <form>.
        resetOnSuccess: _resetOnSuccess,
        action,
        method,
        className,
    }: {
        children: (state: {
            processing: boolean;
            errors: Record<string, string>;
        }) => React.ReactNode;
        resetOnSuccess?: boolean | string[];
        action?: string;
        method?: string;
        className?: string;
    }) => (
        <form
            action={action}
            method={method}
            className={className}
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

const { default: LogRequestForm } = await import('../log-request-form');

/**
 * React Aria's Select keys items by `id`, not `value` — and `defaultValue` is
 * inert on it, because SelectProps extends GlobalDOMAttributes so the prop
 * type-checks as a plain DOM attribute and is then ignored.
 *
 * Getting either wrong submits the item's *rendered text* instead of its value,
 * which reaches the server as `client_id=Sander` and `type=Data export` — both
 * of which fail validation with messages that point nowhere near the cause.
 * The Pest feature tests post to the endpoint directly, so only a test that
 * drives the real control can catch this.
 */
describe('LogRequestForm', () => {
    it('submits the client id and request type, not their labels', async () => {
        const user = userEvent.setup();

        render(<LogRequestForm />);

        await user.click(screen.getByRole('button', { name: 'Log request' }));

        expect(submitted).toHaveLength(1);
        expect(submitted[0].client_id).toBe('7');
        expect(submitted[0].type).toBe('export');
    });

    it('lets a different client be chosen by name and still submits the id', async () => {
        const user = userEvent.setup();
        submitted.length = 0;

        render(<LogRequestForm />);

        await user.click(screen.getAllByRole('button', { name: /Sander/ })[0]);
        await user.click(
            await screen.findByRole('option', { name: 'Marieke' }),
        );
        await user.click(screen.getByRole('button', { name: 'Log request' }));

        expect(submitted[0].client_id).toBe('9');
    });
});
