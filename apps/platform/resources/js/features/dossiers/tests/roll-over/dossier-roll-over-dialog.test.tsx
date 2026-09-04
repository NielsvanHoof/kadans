import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children: (bag: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
    }) => children({ errors: {}, processing: false }),
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: DossierRollOverDialog } =
    await import('@/features/dossiers/roll-over/dossier-roll-over-dialog');

function renderDialog(
    dossier: Partial<{
        id: number;
        title: string;
        period_year: number | null;
        period_label: string | null;
        due_date: string | null;
    }> = {},
) {
    return render(
        <DossierRollOverDialog
            dossier={{
                id: 7,
                title: 'Jaarrekening 2025',
                period_year: 2025,
                period_label: 'Jaarrekening 2025',
                due_date: '2026-05-31',
                ...dossier,
            }}
            open
            onOpenChange={vi.fn()}
        />,
    );
}

// DatePicker submits through a hidden input, and React Aria portals the modal
// out of the render container, so both are queried off document.
function dueDateInput() {
    return document.querySelector<HTMLInputElement>(
        'input[type="hidden"][name="due_date"]',
    );
}

function periodYearInput() {
    return document.querySelector<HTMLInputElement>(
        'input[type="hidden"][name="period_year"]',
    );
}

describe('DossierRollOverDialog', () => {
    it('prefills the period one year ahead with the year swapped into the label', () => {
        renderDialog();

        expect(periodYearInput()).toHaveValue('2026');
        expect(screen.getByLabelText('Period name')).toHaveValue(
            'Jaarrekening 2026',
        );
        expect(screen.getByLabelText('Title')).toHaveValue('Jaarrekening 2026');
    });

    /*
     * The years are a bounded select rather than a free number, so the
     * server-side 1900-2200 range cannot be tripped from the interface. The
     * window is anchored on the source period, not on today, so a firm
     * catching up on a late book year still sees the right years first.
     */
    it('offers a window of years around the source period', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.click(screen.getByLabelText('Period'));

        for (const year of ['2024', '2025', '2026', '2027', '2030']) {
            expect(
                screen.getByRole('option', { name: year }),
            ).toBeInTheDocument();
        }

        expect(
            screen.queryByRole('option', { name: '2031' }),
        ).not.toBeInTheDocument();
    });

    it('can drop the period entirely, posting an empty year', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.click(screen.getByLabelText('Period'));
        await user.click(screen.getByRole('option', { name: 'No period' }));

        expect(periodYearInput()).toHaveValue('');
    });

    it('suggests the due date one year on', () => {
        renderDialog();

        expect(dueDateInput()).toHaveValue('2027-05-31');
    });

    /*
     * Via CalendarDate, not string surgery on the year: 29 February has no
     * counterpart in a non-leap year and must clamp rather than produce
     * 2027-02-29, which parseDate would reject outright.
     */
    it('clamps a leap day to the 28th', () => {
        renderDialog({ due_date: '2024-02-29' });

        expect(dueDateInput()).toHaveValue('2025-02-28');
    });

    it('leaves the due date empty when the source has none', () => {
        renderDialog({ due_date: null });

        expect(dueDateInput()).toHaveValue('');
    });

    it('leaves the reference empty so it cannot collide with the source', () => {
        renderDialog();

        expect(screen.getByLabelText('Reference')).toHaveValue('');
    });

    it('falls back to the title when the source has no period', () => {
        renderDialog({ period_year: null, period_label: null });

        expect(periodYearInput()).toHaveValue('');
        expect(screen.getByLabelText('Period name')).toHaveValue('');
        expect(screen.getByLabelText('Title')).toHaveValue('Jaarrekening 2025');
    });

    it('keeps a label that does not carry the source year', () => {
        renderDialog({ period_label: 'Boekjaar A' });

        expect(screen.getByLabelText('Period name')).toHaveValue('Boekjaar A');
    });

    /*
     * A React Aria Checkbox with a `name` posts "1" when ticked and nothing at
     * all when unticked, which a required|boolean rule reads as a missing field
     * rather than false. The hidden input is what keeps carry_answers always
     * present — assert it directly, because losing it fails server-side only.
     */
    it('always posts carry_answers, defaulting to carrying them', async () => {
        const user = userEvent.setup();
        renderDialog();

        // Queried off document, not the render container: React Aria portals
        // the modal out of it.
        const hidden = () =>
            document.querySelector<HTMLInputElement>(
                'input[type="hidden"][name="carry_answers"]',
            );

        expect(hidden()).toHaveValue('1');

        await user.click(
            screen.getByLabelText('Carry the answers from the previous period'),
        );

        expect(hidden()).toHaveValue('0');
    });
});
