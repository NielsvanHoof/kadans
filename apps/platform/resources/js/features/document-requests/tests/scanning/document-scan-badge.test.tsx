import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { DocumentScanState } from '@/features/document-requests/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: DocumentScanBadge } =
    await import('@/features/document-requests/scanning/document-scan-badge');

describe('DocumentScanBadge', () => {
    it.each([
        ['scanning', 'Running security check'],
        ['blocked', 'Blocked by security check'],
    ] as [DocumentScanState, string][])(
        'renders the label for %s',
        (scanState, label) => {
            render(<DocumentScanBadge scanState={scanState} />);

            expect(screen.getByText(label)).toBeInTheDocument();
        },
    );

    /*
     * A badge on every healthy document would be noise on a row that already
     * carries a review status and a processing status.
     */
    it('renders nothing once the file is ready', () => {
        const { container } = render(<DocumentScanBadge scanState="ready" />);

        expect(container).toBeEmptyDOMElement();
    });

    /*
     * The user-facing vocabulary is deliberately narrower than the scanner's: a
     * detection, an unreadable file and an outright failure are four different
     * operational facts and one identical user-facing one.
     */
    it('says only that the file is blocked, never why', () => {
        render(<DocumentScanBadge scanState="blocked" />);

        const label = screen.getByText('Blocked by security check').textContent;

        expect(label).not.toMatch(/threat|malware|virus|denied|guardduty/i);
    });
});
