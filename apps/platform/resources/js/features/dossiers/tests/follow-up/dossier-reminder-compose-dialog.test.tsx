import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const draftPost = vi.fn();
const toastError = vi.fn();

// The firm's AI opt-outs gate the draft button (see useAiFeatureEnabled).
let reminderDraftEnabled = true;

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
    usePage: () =>
        mockInertiaPage({
            firm_settings: {
                ai: {
                    document_overview: true,
                    checklist_draft: true,
                    reminder_draft: reminderDraftEnabled,
                },
            },
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
    reminderDraftEnabled = true;
});

describe('DossierReminderComposeDialog — AI draft', () => {
    it('hides the draft button when the firm opted out of reminder drafts', async () => {
        const user = userEvent.setup();
        reminderDraftEnabled = false;

        render(<DossierReminderComposeDialog dossierId={7} />);

        await user.click(screen.getByRole('button', { name: 'Send reminder' }));

        expect(
            screen.queryByRole('button', { name: 'Generate draft' }),
        ).toBeNull();
        // Sending by hand must survive the opt-out.
        expect(
            screen.getByRole('button', { name: 'Send reminder' }),
        ).toBeInTheDocument();
        expect(draftPost).not.toHaveBeenCalled();
    });

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
