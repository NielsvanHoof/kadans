import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { DocumentExtractionTable } from '@/features/document-requests/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => ({
    ...mockUseTranslation(),
    // Table summaries lean on :number / :columns / :rows placeholders.
    useTranslation: () => ({
        t: (key: string, replacements?: Record<string, string | number>) => {
            if (!replacements) {
                return key;
            }

            return Object.entries(replacements).reduce(
                (result, [name, value]) =>
                    result.replace(`:${name}`, String(value)),
                key,
            );
        },
        locale: 'en',
        availableLocales: [],
    }),
}));

const { default: ExtractionTables } =
    await import('@/features/document-requests/staff/review/extraction-tables');

/** Textract routinely returns tables with no header row — see id=1 in dev. */
const HEADERLESS: DocumentExtractionTable = {
    title: null,
    headers: [],
    rows: [
        ['Loonheffing', '412,00'],
        ['Pensioen', '98,50'],
    ],
};

const WITH_HEADERS: DocumentExtractionTable = {
    title: 'Transacties',
    headers: ['Datum', 'Bedrag'],
    rows: [['01-01', '10,00']],
};

describe('ExtractionTables', () => {
    it('shows an empty state when there are no tables', () => {
        render(<ExtractionTables tables={[]} />);

        expect(screen.getByText('No tables detected.')).toBeVisible();
    });

    it('renders a headerless table without a header row', () => {
        render(<ExtractionTables tables={[HEADERLESS]} />);

        expect(screen.getByText('Loonheffing')).toBeVisible();
        expect(screen.queryAllByRole('columnheader')).toHaveLength(0);
    });

    it('uses the table title when present and a fallback otherwise', () => {
        render(<ExtractionTables tables={[WITH_HEADERS, HEADERLESS]} />);

        expect(screen.getByText('Transacties')).toBeVisible();
        expect(screen.getByText('Table 2')).toBeVisible();
    });

    it('summarizes column and row counts', () => {
        render(<ExtractionTables tables={[WITH_HEADERS]} />);

        expect(screen.getByText('2 columns · 1 rows')).toBeVisible();
    });

    it('derives the column count from rows when headers are missing', () => {
        render(<ExtractionTables tables={[HEADERLESS]} />);

        expect(screen.getByText('2 columns · 2 rows')).toBeVisible();
    });

    it('lets wide tables scroll horizontally instead of clipping', () => {
        const { container } = render(
            <ExtractionTables tables={[WITH_HEADERS]} />,
        );

        // The shell sets overflow-x-hidden, so the table needs its own scroller.
        expect(container.querySelector('.overflow-x-auto')).not.toBeNull();
    });

    it('expands the first table and collapses the rest', async () => {
        const user = userEvent.setup();
        render(<ExtractionTables tables={[WITH_HEADERS, HEADERLESS]} />);

        expect(screen.getByText('01-01')).toBeVisible();
        // React Aria's DisclosurePanel keeps collapsed content mounted, so this
        // is a visibility assertion rather than a presence one.
        expect(screen.getByText('Loonheffing')).not.toBeVisible();

        await user.click(screen.getByRole('button', { name: /Table 2/ }));

        expect(screen.getByText('Loonheffing')).toBeVisible();
    });
});
