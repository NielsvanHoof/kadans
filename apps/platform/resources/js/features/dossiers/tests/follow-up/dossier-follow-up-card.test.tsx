import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { Dossier } from '@/features/dossiers/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

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

/**
 * The reminder action moved to the Waiting panel, beside the items it is
 * about — see `waiting-chase-header.test.tsx` for its permission gating. What
 * is left here is the chase as a readout.
 */
describe('DossierFollowUpCard', () => {
    it('falls back to placeholder copy when due date, sends, and opens are all empty', () => {
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

    it('renders the chase as facts only, with no action attached', () => {
        render(
            <DossierFollowUpCard
                dossier={dossier({ has_outstanding_client_items: true })}
            />,
        );

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
