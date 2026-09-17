import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());
vi.mock('@inertiajs/react', () => ({
    Form: ({ children }: { children?: unknown }) =>
        typeof children === 'function'
            ? children({ errors: {}, processing: false, submit: () => {} })
            : children,
    Link: ({ children }: { children?: React.ReactNode }) => children,
    // The multi-file case renders the upload form alongside the file list.
    useForm: () => ({
        data: { documents: [] },
        errors: {},
        processing: false,
        progress: null,
        post: vi.fn(),
        reset: vi.fn(),
        setData: vi.fn(),
    }),
}));

const { PortalFileRequestContent } =
    await import('@/features/document-requests/portal/portal-document-request-type-content');

function fileRequest(
    overrides: Partial<PortalDocumentRequest> = {},
): PortalDocumentRequest {
    return {
        id: 7,
        title: 'Payslip',
        type: 'file',
        status: 'submitted',
        can_respond: false,
        instructions: null,
        rejection_reason: null,
        answer_text: null,
        answer_boolean: null,
        answer_option_ids: null,
        field_key: 'field-7',
        configuration: { is_required: true },
        uploaded_documents: [
            {
                id: 42,
                original_filename: 'payslip-january.pdf',
                size_bytes: 12_345,
                uploaded_at: '2026-08-21T09:00:00+00:00',
            },
        ],
        ...overrides,
    } as unknown as PortalDocumentRequest;
}

describe('PortalFileRequestContent uploads', () => {
    /*
     * Clients previously saw a filename and nothing else, so someone who
     * photographed the wrong page of a bank statement had no way to check.
     */
    it('lets the client retrieve a file they uploaded', () => {
        render(
            <PortalFileRequestContent
                dossierId={9}
                documentRequest={fileRequest()}
                canRespond={false}
            />,
        );

        const link = screen.getByRole('link', {
            name: 'Download payslip-january.pdf',
        });

        expect(link).toHaveAttribute(
            'href',
            '/portal/document-requests/7/uploaded-documents/42/download',
        );
    });

    it('offers the download even when the item is locked for review', () => {
        render(
            <PortalFileRequestContent
                dossierId={9}
                documentRequest={fileRequest({ status: 'accepted' })}
                canRespond={false}
            />,
        );

        // Reading back what you sent is not the same permission as changing it.
        expect(
            screen.getByRole('link', { name: 'Download payslip-january.pdf' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Remove payslip-january.pdf',
            }),
        ).not.toBeInTheDocument();
    });

    it('keeps remove available alongside download on multi-file items', () => {
        render(
            <PortalFileRequestContent
                dossierId={9}
                documentRequest={fileRequest({
                    can_respond: true,
                    configuration: {
                        is_required: true,
                        allow_multiple: true,
                    },
                } as Partial<PortalDocumentRequest>)}
                canRespond
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Download payslip-january.pdf' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Remove payslip-january.pdf' }),
        ).toBeInTheDocument();
    });

    it('renders one download per file', () => {
        render(
            <PortalFileRequestContent
                dossierId={9}
                documentRequest={fileRequest({
                    uploaded_documents: [
                        {
                            id: 42,
                            original_filename: 'page-1.pdf',
                            size_bytes: 10,
                            uploaded_at: '2026-08-21T09:00:00+00:00',
                        },
                        {
                            id: 43,
                            original_filename: 'page-2.pdf',
                            size_bytes: 20,
                            uploaded_at: '2026-08-21T09:05:00+00:00',
                        },
                    ],
                } as Partial<PortalDocumentRequest>)}
                canRespond={false}
            />,
        );

        expect(
            screen.getAllByRole('link', { name: /^Download / }),
        ).toHaveLength(2);
    });
});
