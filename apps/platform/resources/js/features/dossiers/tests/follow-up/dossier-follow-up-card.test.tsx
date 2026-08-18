import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Dossier } from '@/features/dossiers/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const useDossierCapabilities = vi.fn();

vi.mock('@/features/dossiers/permissions/dossier-permissions-context', () => ({
    useDossierCapabilities,
}));
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

// The compose dialog owns its own AI-draft/Inertia-form wiring, which is out
// of scope here — this test only cares about whether it is rendered at all.
vi.mock(
    '@/features/dossiers/follow-up/dossier-reminder-compose-dialog',
    () => ({
        default: () => <div data-testid="reminder-dialog" />,
    }),
);

const { default: DossierFollowUpCard } =
    await import('@/features/dossiers/follow-up/dossier-follow-up-card');

function dossier(overrides: Partial<Dossier> = {}): Dossier {
    return {
        id: 1,
        due_date: null,
        responsible_staff: null,
        last_client_message_sent_at: null,
        next_reminder_at: null,
        last_client_opened_at: null,
        reminder_interval_days: null,
        has_outstanding_client_items: true,
        ...overrides,
    } as Dossier;
}

describe('DossierFollowUpCard', () => {
    it('offers the reminder action when the user can send one and items are outstanding', () => {
        useDossierCapabilities.mockReturnValue({ canSendReminder: true });

        render(
            <DossierFollowUpCard
                dossier={dossier({ has_outstanding_client_items: true })}
            />,
        );

        expect(screen.getByTestId('reminder-dialog')).toBeInTheDocument();
        expect(
            screen.getByText('Send a reminder to nudge the client.'),
        ).toBeInTheDocument();
    });

    it('hides the reminder action and explains why when the user lacks permission', () => {
        useDossierCapabilities.mockReturnValue({ canSendReminder: false });

        render(
            <DossierFollowUpCard
                dossier={dossier({ has_outstanding_client_items: true })}
            />,
        );

        expect(screen.queryByTestId('reminder-dialog')).not.toBeInTheDocument();
        expect(
            screen.getByText('You cannot send reminders for this dossier.'),
        ).toBeInTheDocument();
    });

    it('hides the reminder action when nothing is outstanding, even with permission', () => {
        useDossierCapabilities.mockReturnValue({ canSendReminder: true });

        render(
            <DossierFollowUpCard
                dossier={dossier({ has_outstanding_client_items: false })}
            />,
        );

        expect(screen.queryByTestId('reminder-dialog')).not.toBeInTheDocument();
        expect(
            screen.getByText('No client follow-up is needed.'),
        ).toBeInTheDocument();
    });

    it('falls back to placeholder copy when due date, sends, and opens are all empty', () => {
        useDossierCapabilities.mockReturnValue({ canSendReminder: false });

        render(
            <DossierFollowUpCard
                dossier={dossier({ has_outstanding_client_items: false })}
            />,
        );

        expect(screen.getByText('No due date')).toBeInTheDocument();
        expect(screen.getByText('Unassigned')).toBeInTheDocument();
        expect(screen.getByText('Not sent yet')).toBeInTheDocument();
        expect(screen.getByText('No reminder scheduled')).toBeInTheDocument();
        expect(screen.getByText('Not opened yet')).toBeInTheDocument();
        expect(screen.getByText('Automatic reminders off')).toBeInTheDocument();
    });
});
