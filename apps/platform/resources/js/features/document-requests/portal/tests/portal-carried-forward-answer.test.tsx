import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children:
            | ((props: Record<string, unknown>) => React.ReactNode)
            | React.ReactNode;
    }) => (
        <form>
            {typeof children === 'function'
                ? children({ errors: {}, processing: false, submit: vi.fn() })
                : children}
        </form>
    ),
}));

const { default: PortalDocumentRequestsCard } =
    await import('@/features/document-requests/portal/portal-document-requests-card');

function request(
    overrides: Partial<PortalDocumentRequest> = {},
): PortalDocumentRequest {
    return {
        id: 11,
        title: 'Registered address',
        type: 'text',
        status: 'pending',
        can_respond: true,
        is_carried_forward: true,
        answer_text: '221B Baker Street',
        answer_boolean: null,
        answer_option_ids: null,
        uploaded_documents: [],
        field_key: 'field-11',
        instructions: null,
        rejection_reason: null,
        configuration: { is_required: true },
        ...overrides,
    } as PortalDocumentRequest;
}

describe('carried-forward portal answers', () => {
    it('shows last year’s answer as a suggestion, not a prefilled field', () => {
        render(
            <PortalDocumentRequestsCard
                dossierId={7}
                firmName="Acme"
                documentRequests={[request()]}
                nextIncompleteRequestId={11}
            />,
        );

        expect(screen.getByText('Your answer last year')).toBeInTheDocument();
        expect(screen.getByText('221B Baker Street')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Still correct' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Change answer' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('221B Baker Street')).toHaveAttribute(
            'type',
            'hidden',
        );
    });

    it('lets the client change the suggestion and then edit this period’s answer', async () => {
        const user = userEvent.setup();
        render(
            <PortalDocumentRequestsCard
                dossierId={7}
                firmName="Acme"
                documentRequests={[request()]}
                nextIncompleteRequestId={11}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Change answer' }));

        expect(screen.getByText('Your answer last year')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Still correct' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('textbox')).toHaveValue('221B Baker Street');
        expect(
            screen.getByRole('button', { name: 'Submit answer' }),
        ).toBeInTheDocument();
    });

    it('leaves ordinary pending fields as empty forms', () => {
        render(
            <PortalDocumentRequestsCard
                dossierId={7}
                firmName="Acme"
                documentRequests={[
                    request({
                        is_carried_forward: false,
                        answer_text: null,
                    }),
                ]}
                nextIncompleteRequestId={11}
            />,
        );

        expect(
            screen.queryByText('Your answer last year'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Still correct' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('textbox')).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'Submit answer' }),
        ).toBeInTheDocument();
    });

    it('shows a yes/no suggestion without preselecting this period’s radios', () => {
        render(
            <PortalDocumentRequestsCard
                dossierId={7}
                firmName="Acme"
                documentRequests={[
                    request({
                        type: 'boolean',
                        answer_text: null,
                        answer_boolean: false,
                    }),
                ]}
                nextIncompleteRequestId={11}
            />,
        );

        expect(screen.getByText('Your answer last year')).toBeInTheDocument();
        expect(screen.getByText('No')).toBeInTheDocument();
        expect(screen.queryByRole('radio')).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('0')).toHaveAttribute('type', 'hidden');
        expect(screen.getByDisplayValue('0')).toHaveAttribute(
            'name',
            'answer_boolean',
        );
    });
});
