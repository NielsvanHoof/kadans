import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { DocumentExtraction } from '@/features/document-requests/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => ({
    ...mockUseTranslation(),
    // Tab labels lean on :count placeholders; keep them readable in assertions.
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

const { default: DocumentExtractionView } =
    await import('@/features/document-requests/staff/review/document-extraction-view');

function extraction(
    overrides: Partial<DocumentExtraction> = {},
): DocumentExtraction {
    return {
        document_type: 'payslip',
        summary: 'January payslip',
        fields: [
            {
                label: 'Werkgever',
                value: 'Acme BV',
                confidence: 0.99,
                sensitivity: null,
            },
        ],
        tables: [{ title: null, headers: ['Datum'], rows: [['01-01']] }],
        notes: ['Slight blur on footer'],
        confidence: 0.94,
        ...overrides,
    };
}

describe('DocumentExtractionView', () => {
    it('renders all four tabs with counts', () => {
        render(
            <DocumentExtractionView extraction={extraction()} rawJson="{}" />,
        );

        expect(screen.getByRole('tab', { name: 'Overview' })).toBeVisible();
        expect(screen.getByRole('tab', { name: '1 fields' })).toBeVisible();
        expect(screen.getByRole('tab', { name: '1 tables' })).toBeVisible();
        expect(screen.getByRole('tab', { name: 'Full data' })).toBeVisible();
    });

    it('opens on the overview tab, showing type, confidence and notes', () => {
        render(
            <DocumentExtractionView extraction={extraction()} rawJson="{}" />,
        );

        expect(screen.getByText('January payslip')).toBeVisible();
        expect(screen.getByText('Slight blur on footer')).toBeVisible();
        // Fields belong to another panel and should not be rendered yet.
        expect(screen.queryByText('Acme BV')).not.toBeInTheDocument();
    });

    it('swaps the visible panel when another tab is selected', async () => {
        const user = userEvent.setup();
        render(
            <DocumentExtractionView extraction={extraction()} rawJson="{}" />,
        );

        await user.click(screen.getByRole('tab', { name: '1 fields' }));

        expect(screen.getByText('Acme BV')).toBeVisible();
        expect(screen.queryByText('January payslip')).not.toBeInTheDocument();
    });

    it('keeps the tab set stable when there is no table or field data', () => {
        render(
            <DocumentExtractionView
                extraction={extraction({ fields: [], tables: [] })}
                rawJson="{}"
            />,
        );

        // Tabs must not appear or vanish as OCR results stream in mid-review.
        expect(screen.getByRole('tab', { name: '0 fields' })).toBeVisible();
        expect(screen.getByRole('tab', { name: '0 tables' })).toBeVisible();
    });

    it('keeps Full data empty when the raw payload is missing', async () => {
        const user = userEvent.setup();
        render(
            <DocumentExtractionView extraction={extraction()} rawJson={null} />,
        );

        await user.click(screen.getByRole('tab', { name: 'Full data' }));

        expect(screen.getByText('No raw data is available.')).toBeVisible();
    });

    it('offers a reveal on Full data when the raw payload is present', async () => {
        const user = userEvent.setup();
        render(
            <DocumentExtractionView
                extraction={extraction()}
                rawJson='{"document_type":"payslip"}'
            />,
        );

        await user.click(screen.getByRole('tab', { name: 'Full data' }));

        expect(
            screen.getByRole('button', { name: 'Show full data' }),
        ).toBeVisible();
        expect(
            screen.queryByText('No raw data is available.'),
        ).not.toBeInTheDocument();
    });

    it('shows an empty state when there is no extraction at all', () => {
        render(<DocumentExtractionView extraction={null} rawJson={null} />);

        expect(
            screen.getByText(
                'No extraction data is available for this file yet.',
            ),
        ).toBeVisible();
        expect(screen.queryByRole('tab')).not.toBeInTheDocument();
    });
});
