import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: DocumentThumbnail } =
    await import('@/features/document-requests/scanning/document-thumbnail');

describe('DocumentThumbnail', () => {
    it('shows the rendered preview when there is one', () => {
        render(
            <DocumentThumbnail
                filename="payslip.pdf"
                thumbnailUrl="/thumb.jpg"
                scanState="ready"
            />,
        );

        expect(
            screen.getByRole('presentation', { hidden: true }),
        ).toHaveAttribute('src', '/thumb.jpg');
    });

    /*
     * Most documents will never have a preview — the format may have no
     * renderer, or the verdict may not have been clean — so the icon is the base
     * case rather than an error path.
     */
    it('falls back to a file-type icon when there is no preview', () => {
        const { container } = render(
            <DocumentThumbnail
                filename="payslip.pdf"
                thumbnailUrl={null}
                scanState="ready"
            />,
        );

        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('svg')).toBeInTheDocument();
    });

    /*
     * A preview can disappear between the page rendering and the image loading:
     * a document deleted in another tab, or a re-check that withdrew it. A
     * broken image in a list is worse than an icon.
     */
    it('falls back to the icon when the preview fails to load', () => {
        const { container } = render(
            <DocumentThumbnail
                filename="payslip.pdf"
                thumbnailUrl="/gone.jpg"
                scanState="ready"
            />,
        );

        const image = container.querySelector('img');
        expect(image).not.toBeNull();

        fireEvent.error(image as HTMLImageElement);

        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('svg')).toBeInTheDocument();
    });

    it('shows a placeholder rather than an icon while the check is running', () => {
        render(
            <DocumentThumbnail
                filename="payslip.pdf"
                thumbnailUrl={null}
                scanState="scanning"
            />,
        );

        expect(
            screen.getByLabelText('Running security check'),
        ).toBeInTheDocument();
    });
});
