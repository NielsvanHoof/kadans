import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Dossier } from '@/features/dossiers/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => ({
    ...mockUseTranslation(),
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

const { default: DossierContextRail } = await import(
    '@/features/dossiers/show/dossier-context-rail'
);
const { default: DossierStatusSheet } = await import(
    '@/features/dossiers/show/dossier-status-sheet'
);

function dossier(overrides: Partial<Dossier> = {}): Dossier {
    return {
        id: 7,
        title: 'Jaarrekening',
        client: { name: 'Biemans B.V.', email: 'info@biemans.test' },
        due_date: '2026-09-05',
        days_remaining: 12,
        responsible_staff: { id: 1, name: 'Sander', email: 's@acme.test' },
        reminder_interval_days: 7,
        last_client_message_sent_at: '2026-08-21T09:00:00+00:00',
        last_client_opened_at: null,
        next_reminder_at: null,
        review_summary: {
            total: 8,
            pending: 3,
            submitted: 2,
            rejected: 1,
            accepted: 2,
        },
        ...overrides,
    } as Dossier;
}

describe('DossierContextRail', () => {
    it('shows the client, due date, and review counts', () => {
        render(<DossierContextRail dossier={dossier()} />);

        expect(screen.getByText('Biemans B.V.')).toBeInTheDocument();
        expect(screen.getByText('info@biemans.test')).toBeInTheDocument();
        expect(screen.getByText('Due in 12 days')).toBeInTheDocument();
        expect(screen.getByText('2 of 8 approved')).toBeInTheDocument();
        expect(screen.getByText('Ready to review')).toBeInTheDocument();
        expect(screen.getByText('Sander')).toBeInTheDocument();
    });

    it('falls back when due date and owner are missing', () => {
        render(
            <DossierContextRail
                dossier={dossier({
                    due_date: null,
                    days_remaining: null,
                    responsible_staff: null,
                    reminder_interval_days: null,
                })}
            />,
        );

        expect(screen.getAllByText('No due date').length).toBeGreaterThan(0);
        expect(screen.getByText('Unassigned')).toBeInTheDocument();
        expect(screen.getByText('Automatic reminders off')).toBeInTheDocument();
    });

    it('offers a Dossier status trigger for the mobile sheet', () => {
        render(
            <DossierStatusSheet>
                <p>rail-body</p>
            </DossierStatusSheet>,
        );

        expect(
            screen.getByRole('button', { name: 'Dossier status' }),
        ).toBeInTheDocument();
    });
});
