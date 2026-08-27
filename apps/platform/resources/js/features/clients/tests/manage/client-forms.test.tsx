import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

/*
 * The Form mock renders its children with a fixed error bag so the invalid state
 * can be asserted without a round trip. `errors` is the only prop the render
 * callback needs here; everything Inertia-only is dropped rather than spread onto
 * the <form>, per .ai/rules/js-tests.md.
 */
const errors: Record<string, string> = {};
const formState = { processing: false };

vi.mock('@inertiajs/react', () => ({
    usePage: () =>
        mockInertiaPage({ current_firm: { name: 'Acme', slug: 'acme' } }),
    Form: ({
        children,
        action,
        method,
    }: {
        children: (props: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
        action?: string;
        method?: string;
    }) => (
        <form action={action} method={method} data-testid="client-form">
            {children({ errors, processing: formState.processing })}
        </form>
    ),
}));

const { default: CreateClientForm } =
    await import('@/features/clients/manage/create-client-form');
const { default: EditClientForm } =
    await import('@/features/clients/manage/edit-client-form');

beforeEach(() => {
    for (const key of Object.keys(errors)) {
        delete errors[key];
    }

    formState.processing = false;
});

describe('CreateClientForm', () => {
    it('posts to the workspace-scoped store route', () => {
        render(<CreateClientForm />);

        expect(screen.getByTestId('client-form')).toHaveAttribute(
            'action',
            '/workspaces/acme/clients',
        );
    });

    it('requires a name and a typed email address', () => {
        render(<CreateClientForm />);

        const name = screen.getByLabelText('Name');
        const email = screen.getByLabelText('Email address');

        expect(name).toBeRequired();
        expect(email).toBeRequired();
        // type=email is the only client-side check that the address is shaped
        // like one; the server validates properly, but losing this turns an
        // obvious typo into a round trip.
        expect(email).toHaveAttribute('type', 'email');
    });

    it('marks the offending field invalid and shows its message', () => {
        errors.email = 'That email is already taken.';

        render(<CreateClientForm />);

        expect(screen.getByLabelText('Email address')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        expect(screen.getByLabelText('Name')).toHaveAttribute(
            'aria-invalid',
            'false',
        );
        expect(
            screen.getByText('That email is already taken.'),
        ).toBeInTheDocument();
    });

    it('disables submit while the request is in flight', () => {
        formState.processing = true;

        render(<CreateClientForm />);

        // A second submit would create a duplicate client, and the request is
        // fast enough that an impatient double-click is realistic.
        expect(screen.getByRole('button', { name: /Create client/ })).toBeDisabled();
    });
});

describe('EditClientForm', () => {
    it('targets the specific client and spoofs PATCH', () => {
        render(
            <EditClientForm
                client={{ id: 42, name: 'Ada', email: 'ada@example.com' }}
            />,
        );

        const form = screen.getByTestId('client-form');

        expect(form).toHaveAttribute(
            'action',
            '/workspaces/acme/clients/42?_method=PATCH',
        );
        expect(form).toHaveAttribute('method', 'post');
    });

    it('opens with the client already filled in', () => {
        render(
            <EditClientForm
                client={{ id: 42, name: 'Ada', email: 'ada@example.com' }}
            />,
        );

        // defaultValue, not value — an accidental switch to a controlled input
        // without state would render a permanently read-only form.
        expect(screen.getByLabelText('Name')).toHaveValue('Ada');
        expect(screen.getByLabelText('Email address')).toHaveValue(
            'ada@example.com',
        );
    });
});
