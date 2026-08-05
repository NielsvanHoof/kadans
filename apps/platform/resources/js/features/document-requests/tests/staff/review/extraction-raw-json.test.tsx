import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: ExtractionRawJson } =
    await import('@/features/document-requests/staff/review/extraction-raw-json');

const RAW = JSON.stringify({ fields: [{ label: 'BSN', value: '287505030' }] });

describe('ExtractionRawJson', () => {
    it('shows an empty state without a payload', () => {
        render(<ExtractionRawJson rawJson={null} />);

        expect(screen.getByText('No raw payload is available.')).toBeVisible();
    });

    it('warns about unmasked personal data before revealing anything', () => {
        render(<ExtractionRawJson rawJson={RAW} />);

        expect(
            screen.getByText(
                'This contains unmasked personal data, including any BSN and IBAN found in the document.',
            ),
        ).toBeVisible();
    });

    it('keeps the payload out of the DOM until it is revealed', async () => {
        const user = userEvent.setup();
        render(<ExtractionRawJson rawJson={RAW} />);

        expect(screen.queryByText(/287505030/)).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Show raw JSON' }));

        expect(screen.getByText(/287505030/)).toBeVisible();
    });

    it('hides the payload again on toggle', async () => {
        const user = userEvent.setup();
        render(<ExtractionRawJson rawJson={RAW} />);

        await user.click(screen.getByRole('button', { name: 'Show raw JSON' }));
        await user.click(screen.getByRole('button', { name: 'Hide raw JSON' }));

        expect(screen.queryByText(/287505030/)).not.toBeInTheDocument();
    });

    it('only offers copy once the payload is revealed', async () => {
        const user = userEvent.setup();
        render(<ExtractionRawJson rawJson={RAW} />);

        expect(
            screen.queryByRole('button', { name: 'Copy' }),
        ).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Show raw JSON' }));

        expect(screen.getByRole('button', { name: 'Copy' })).toBeVisible();
    });
});
