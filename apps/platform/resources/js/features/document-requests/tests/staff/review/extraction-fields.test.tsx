import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { DocumentExtractionField } from '@/features/document-requests/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => ({
    ...mockUseTranslation(),
    // The no-match empty state leans on a :query placeholder.
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

const { default: ExtractionFields } =
    await import('@/features/document-requests/staff/review/extraction-fields');

const FIELDS: DocumentExtractionField[] = [
    {
        label: 'Werkgever',
        value: 'Acme BV',
        confidence: 0.99,
        sensitivity: null,
    },
    {
        label: 'Netto loon',
        value: '2450,00',
        confidence: 0.97,
        sensitivity: null,
    },
    {
        // Deliberately not literally "BSN": the sensitivity badge renders that
        // word too, which would make text queries ambiguous.
        label: 'Burgerservicenummer',
        value: '287505030',
        confidence: 0.98,
        sensitivity: 'bsn',
    },
];

describe('ExtractionFields', () => {
    it('shows an empty state when there are no fields', () => {
        render(<ExtractionFields fields={[]} />);

        expect(screen.getByText('No form fields detected.')).toBeVisible();
    });

    it('masks a sensitive value until it is revealed', async () => {
        const user = userEvent.setup();
        render(<ExtractionFields fields={FIELDS} />);

        expect(screen.queryByText('287505030')).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'Reveal sensitive value' }),
        );

        expect(screen.getByText('287505030')).toBeVisible();
    });

    it('filters by label', async () => {
        const user = userEvent.setup();
        render(<ExtractionFields fields={FIELDS} />);

        await user.type(screen.getByLabelText('Search fields'), 'werkg');

        expect(screen.getByText('Acme BV')).toBeVisible();
        expect(screen.queryByText('Netto loon')).not.toBeInTheDocument();
    });

    it('filters by value', async () => {
        const user = userEvent.setup();
        render(<ExtractionFields fields={FIELDS} />);

        await user.type(screen.getByLabelText('Search fields'), '2450');

        expect(screen.getByText('Netto loon')).toBeVisible();
        expect(screen.queryByText('Werkgever')).not.toBeInTheDocument();
    });

    it('keeps a sensitive value masked when the search matches it', async () => {
        const user = userEvent.setup();
        render(<ExtractionFields fields={FIELDS} />);

        await user.type(screen.getByLabelText('Search fields'), '287505030');

        // The field is matched and listed, but searching must never reveal it.
        expect(screen.getByText('Burgerservicenummer')).toBeVisible();
        expect(screen.queryByText('287505030')).not.toBeInTheDocument();
    });

    it('shows an empty state when nothing matches the query', async () => {
        const user = userEvent.setup();
        render(<ExtractionFields fields={FIELDS} />);

        await user.type(screen.getByLabelText('Search fields'), 'zzzz');

        expect(screen.getByText('No fields match “zzzz”.')).toBeVisible();
    });
});
