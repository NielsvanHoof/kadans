import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { UploadedDocument } from '@/features/document-requests/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: DocumentProcessingPanel } =
    await import('@/features/document-requests/staff/review/document-processing-panel');

function uploadedDocument(
    overrides: Partial<UploadedDocument> = {},
): UploadedDocument {
    return {
        id: 1,
        original_filename: 'statement.pdf',
        size_bytes: 2048,
        uploaded_at: '2026-01-01T10:00:00Z',
        processing_status: 'completed',
        processing_error: null,
        ...overrides,
    } as UploadedDocument;
}

describe('DocumentProcessingPanel', () => {
    it('offers extraction only after OCR completes', () => {
        const { rerender } = render(
            <DocumentProcessingPanel
                uploadedDocument={uploadedDocument({
                    processing_status: 'processing',
                })}
                canDownload
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'View extraction' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Analyzing document structure (forms and tables)…',
            ),
        ).toBeInTheDocument();

        rerender(
            <DocumentProcessingPanel
                uploadedDocument={uploadedDocument({
                    processing_status: 'completed',
                })}
                canDownload
            />,
        );

        expect(
            screen.getByRole('link', { name: 'View extraction' }),
        ).toBeInTheDocument();
    });

    it('shows the processing error when OCR fails', () => {
        render(
            <DocumentProcessingPanel
                uploadedDocument={uploadedDocument({
                    processing_status: 'failed',
                    processing_error: 'Textract timed out',
                })}
                canDownload={false}
            />,
        );

        const errorLine = screen.getByText('Processing failed').closest('p');
        expect(errorLine?.textContent).toBe(
            'Processing failed: Textract timed out',
        );
    });

    it('hides download when the user lacks permission', () => {
        render(
            <DocumentProcessingPanel
                uploadedDocument={uploadedDocument()}
                canDownload={false}
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'Download' }),
        ).not.toBeInTheDocument();
    });

    it('shows remove only when explicitly allowed', () => {
        const { rerender } = render(
            <DocumentProcessingPanel
                uploadedDocument={uploadedDocument()}
                canDownload
                canRemove={false}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Remove/ }),
        ).not.toBeInTheDocument();

        rerender(
            <DocumentProcessingPanel
                uploadedDocument={uploadedDocument()}
                canDownload
                canRemove
            />,
        );

        expect(
            screen.getByRole('button', { name: /Remove/ }),
        ).toBeInTheDocument();
    });
});
