import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

const draftPost = vi.fn();
const toastError = vi.fn();

vi.mock('sonner', () => ({
    toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children: (bag: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
    }) => children({ errors: {}, processing: false }),
    useHttp: () => ({
        post: draftPost,
        processing: false,
    }),
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: DossierReminderComposeDialog } =
    await import('@/features/dossiers/follow-up/dossier-reminder-compose-dialog');

beforeEach(() => {
    draftPost.mockReset();
    toastError.mockReset();
});

describe('DossierReminderComposeDialog — AI draft', () => {
    it('fills the message when the draft endpoint succeeds', async () => {
        const user = userEvent.setup();
        draftPost.mockResolvedValueOnce({ message: 'Please upload your ID.' });

        render(<DossierReminderComposeDialog dossierId={7} />);

        await user.click(screen.getByRole('button', { name: 'Send reminder' }));
        await user.click(
            screen.getByRole('button', { name: 'Generate draft' }),
        );

        await waitFor(() =>
            expect(
                screen.getByDisplayValue('Please upload your ID.'),
            ).toBeInTheDocument(),
        );
        expect(toastError).not.toHaveBeenCalled();
    });

    it('toasts when the draft endpoint fails and leaves the trigger actionable', async () => {
        const user = userEvent.setup();
        draftPost.mockRejectedValueOnce(new Error('llm down'));

        render(<DossierReminderComposeDialog dossierId={7} />);

        await user.click(screen.getByRole('button', { name: 'Send reminder' }));
        await user.click(
            screen.getByRole('button', { name: 'Generate draft' }),
        );

        await waitFor(() =>
            expect(toastError).toHaveBeenCalledWith(
                'Could not generate a draft. Please try again.',
            ),
        );
        expect(
            screen.getByRole('button', { name: 'Generate draft' }),
        ).toBeEnabled();
    });
});
