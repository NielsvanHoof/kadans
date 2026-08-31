import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { Dossier } from '@/features/dossiers/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => ({
    ...mockUseTranslation(),
    // The deadline and cadence copy leans on :count / :days placeholders; keep
    // them interpolated so assertions read like the rendered page.
    useTranslation: () => ({
        t: (key: string, replacements?: Record<string, string | number>) =>
            replacements
                ? Object.entries(replacements).reduce(
                      (result, [name, value]) =>
                          result.replace(`:${name}`, String(value)),
                      key,
                  )
                : key,
        locale: 'en',
        availableLocales: [],
    }),
}));
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: DossierChaseLine } =
    await import('@/features/dossiers/follow-up/dossier-chase-line');

function dossier(overrides: Partial<Dossier> = {}): Dossier {
    return {
        id: 7,
        due_date: '2026-09-05',
        days_remaining: 12,
        last_client_message_sent_at: '2026-08-21T09:00:00+00:00',
        last_client_opened_at: '2026-08-22T09:00:00+00:00',
        next_reminder_at: '2026-08-28T09:00:00+00:00',
        reminder_interval_days: 7,
        responsible_staff: { id: 1, name: 'Sander', email: 's@acme.test' },
        ...overrides,
    } as Dossier;
}

/** The marks in chase order, as the reader sees them. */
function markLabels(): string[] {
    return screen
        .getAllByRole('listitem')
        .map((item) => item.textContent ?? '');
}

describe('DossierChaseLine', () => {
    it('lays the four moments out in chase order', () => {
        render(<DossierChaseLine dossier={dossier()} />);

        expect(markLabels()).toHaveLength(4);
        expect(markLabels()[0]).toContain('Sent');
        expect(markLabels()[1]).toContain('Opened');
        expect(markLabels()[2]).toContain('Reminder');
        expect(markLabels()[3]).toContain('Due');
    });

    /**
     * The point of the line: a moment that has not happened keeps its
     * placeholder, so "sent but never opened, no reminder queued" is readable
     * without reading any dates.
     */
    it('keeps a placeholder on every moment that has not happened', () => {
        render(
            <DossierChaseLine
                dossier={dossier({
                    last_client_message_sent_at: null,
                    last_client_opened_at: null,
                    next_reminder_at: null,
                    due_date: null,
                    days_remaining: null,
                })}
            />,
        );

        expect(screen.getByText('Not sent yet')).toBeInTheDocument();
        expect(screen.getByText('Not opened yet')).toBeInTheDocument();
        expect(screen.getByText('No reminder scheduled')).toBeInTheDocument();
        expect(screen.getByText('No due date')).toBeInTheDocument();
    });

    it('reads how long is left on the deadline', () => {
        render(<DossierChaseLine dossier={dossier()} />);

        expect(screen.getByText('Due in 12 days')).toBeInTheDocument();
    });

    /**
     * Overdue is signed server-side and carries an icon as well as colour, so
     * the state is never conveyed by colour alone.
     */
    it('calls out an overdue deadline', () => {
        render(<DossierChaseLine dossier={dossier({ days_remaining: -9 })} />);

        expect(screen.getByText('9 days overdue')).toBeInTheDocument();
    });

    it('says nothing about time left when there is no due date', () => {
        render(
            <DossierChaseLine
                dossier={dossier({ due_date: null, days_remaining: null })}
            />,
        );

        expect(screen.queryByText(/overdue|Due in|Due today/)).toBeNull();
    });

    it('captions the chase with its owner and cadence', () => {
        render(<DossierChaseLine dossier={dossier()} />);

        expect(screen.getByText('Sander')).toBeInTheDocument();
        expect(screen.getByText('reminder every 7 days')).toBeInTheDocument();
    });

    it('falls back to unassigned with reminders off', () => {
        render(
            <DossierChaseLine
                dossier={dossier({
                    responsible_staff: null,
                    reminder_interval_days: null,
                })}
            />,
        );

        expect(screen.getByText('Unassigned')).toBeInTheDocument();
        expect(screen.getByText('Automatic reminders off')).toBeInTheDocument();
    });
});
