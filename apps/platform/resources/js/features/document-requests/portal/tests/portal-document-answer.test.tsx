import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { mockUseTranslation } from '@/test/mocks';

const submit = vi.fn();

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
                ? children({ errors: {}, processing: false, submit })
                : children}
        </form>
    ),
}));

const {
    PortalBooleanDocumentAnswer,
    PortalChoiceDocumentAnswer,
    PortalMultiSelectDocumentAnswer,
} = await import('@/features/document-requests/portal/portal-document-answer');

function request(
    overrides: Partial<PortalDocumentRequest> & { id: number },
): PortalDocumentRequest {
    return {
        title: 'Question',
        type: 'choice',
        status: 'pending',
        can_respond: true,
        answer_text: null,
        answer_boolean: null,
        answer_option_ids: null,
        uploaded_documents: [],
        configuration: {
            is_required: true,
            options: [
                { id: 'opt-a', label: 'By post' },
                { id: 'opt-b', label: 'By email' },
            ],
        },
        ...overrides,
    } as PortalDocumentRequest;
}

describe('portal answer controls', () => {
    beforeEach(() => {
        submit.mockClear();
    });

    it('renders single choice as one radio group, not a grid of buttons', () => {
        render(
            <PortalChoiceDocumentAnswer documentRequest={request({ id: 1 })} />,
        );

        // One group with roving focus, rather than N independent forms.
        expect(screen.getAllByRole('radio')).toHaveLength(2);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('saves as soon as a choice is picked, posting the option id', async () => {
        const user = userEvent.setup();
        render(
            <PortalChoiceDocumentAnswer documentRequest={request({ id: 1 })} />,
        );

        const byEmail = screen.getByRole('radio', { name: 'By email' });
        await user.click(byEmail);

        expect(submit).toHaveBeenCalledTimes(1);
        expect(byEmail).toBeChecked();
        expect(byEmail).toHaveAttribute('name', 'answer_option_ids[]');
        expect(byEmail).toHaveAttribute('value', 'opt-b');
    });

    it('preselects the stored choice', () => {
        render(
            <PortalChoiceDocumentAnswer
                documentRequest={request({
                    id: 1,
                    answer_option_ids: ['opt-a'],
                })}
            />,
        );

        expect(screen.getByRole('radio', { name: 'By post' })).toBeChecked();
    });

    it('renders yes/no as radios and submits on pick', async () => {
        const user = userEvent.setup();
        render(
            <PortalBooleanDocumentAnswer
                documentRequest={request({ id: 2, type: 'boolean' })}
            />,
        );

        const yes = screen.getByRole('radio', { name: 'Yes' });
        expect(screen.getByRole('radio', { name: 'No' })).toBeInTheDocument();

        await user.click(yes);

        expect(submit).toHaveBeenCalledTimes(1);
        expect(yes).toHaveAttribute('name', 'answer_boolean');
        expect(yes).toHaveAttribute('value', '1');
    });

    it('preselects a stored no answer', () => {
        render(
            <PortalBooleanDocumentAnswer
                documentRequest={request({
                    id: 2,
                    type: 'boolean',
                    answer_boolean: false,
                })}
            />,
        );

        expect(screen.getByRole('radio', { name: 'No' })).toBeChecked();
    });

    it('renders multi-select with real checkboxes and an explicit submit', async () => {
        const user = userEvent.setup();
        render(
            <PortalMultiSelectDocumentAnswer
                documentRequest={request({
                    id: 3,
                    type: 'multi_select',
                    answer_option_ids: ['opt-a'],
                })}
            />,
        );

        const boxes = screen.getAllByRole('checkbox');
        expect(boxes).toHaveLength(2);
        expect(boxes[0]).toBeChecked();
        expect(boxes[0]).toHaveAttribute('name', 'answer_option_ids[]');

        // Multi-select does not autosave — the client says when they are done.
        await user.click(boxes[1]);
        expect(submit).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: /Update answer/ }),
        ).toBeInTheDocument();
    });
});
