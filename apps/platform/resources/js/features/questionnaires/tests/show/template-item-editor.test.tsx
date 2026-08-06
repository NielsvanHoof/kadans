import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DraftedChecklistItem } from '@/features/questionnaires/types';
import { mockUseTranslation } from '@/test/mocks';

/**
 * TemplateItemEditor's own logic worth verifying lives in the AI "suggest
 * items" flow: generate → apply one / apply all / dismiss. Items are kept
 * empty in every test so the (dnd-kit powered) SortableList branch is never
 * mounted — only the EmptyState renders, and the suggestion box is
 * independent of it.
 */
const reorderPost = vi.fn();
const draftPost = vi.fn();
const toastError = vi.fn();
const routerPost = vi.fn(
    (
        _url: string,
        _data: unknown,
        options?: {
            onSuccess?: () => void;
            onError?: () => void;
            onFinish?: () => void;
        },
    ) => {
        options?.onSuccess?.();
        options?.onFinish?.();
    },
);

vi.mock('sonner', () => ({
    toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children: (bag: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
    }) => children({ errors: {}, processing: false }),
    router: { post: routerPost },
    useHttp: (initial: Record<string, unknown>) => {
        if ('description' in initial) {
            return { post: draftPost, setData: vi.fn(), processing: false };
        }

        return { post: reorderPost, setData: vi.fn() };
    },
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: TemplateItemEditor } =
    await import('@/features/questionnaires/show/template-item-editor');

function suggestion(
    overrides: Partial<DraftedChecklistItem> = {},
): DraftedChecklistItem {
    return {
        title: 'Proof of address',
        instructions: 'A recent utility bill',
        type: 'file',
        ...overrides,
    };
}

beforeEach(() => {
    reorderPost.mockReset();
    draftPost.mockReset();
    toastError.mockReset();
    routerPost.mockClear();
});

async function generateSuggestions(
    user: ReturnType<typeof userEvent.setup>,
    items: DraftedChecklistItem[],
) {
    draftPost.mockResolvedValueOnce({ items });

    await user.type(
        screen.getByLabelText('Suggest items with AI'),
        'Onboarding a new client',
    );
    await user.click(screen.getByRole('button', { name: 'Suggest items' }));

    await waitFor(() =>
        expect(screen.getByText(':count suggestions')).toBeInTheDocument(),
    );
}

describe('TemplateItemEditor — AI suggestions', () => {
    it('shows the empty state when there are no items yet', () => {
        render(<TemplateItemEditor templateId={1} items={[]} canManage />);

        expect(
            screen.getByText('No items yet. Add your first question below.'),
        ).toBeInTheDocument();
    });

    it('does not render the suggestion tools for a read-only viewer', () => {
        render(
            <TemplateItemEditor templateId={1} items={[]} canManage={false} />,
        );

        expect(
            screen.queryByLabelText('Suggest items with AI'),
        ).not.toBeInTheDocument();
    });

    it('lists suggestions returned by the draft endpoint', async () => {
        const user = userEvent.setup();
        render(<TemplateItemEditor templateId={1} items={[]} canManage />);

        await generateSuggestions(user, [suggestion()]);

        expect(screen.getByText('Proof of address')).toBeInTheDocument();
        expect(draftPost).toHaveBeenCalledWith(
            expect.stringContaining('/workspaces/acme/templates/1/draft-items'),
        );
    });

    it('applies one suggestion and removes only that one from the list', async () => {
        const user = userEvent.setup();
        render(<TemplateItemEditor templateId={1} items={[]} canManage />);

        await generateSuggestions(user, [
            suggestion({ title: 'Proof of address' }),
            suggestion({ title: 'Passport copy' }),
        ]);

        await user.click(screen.getAllByRole('button', { name: 'Use' })[0]);

        await waitFor(() =>
            expect(
                screen.queryByText('Proof of address'),
            ).not.toBeInTheDocument(),
        );
        expect(screen.getByText('Passport copy')).toBeInTheDocument();
        expect(routerPost).toHaveBeenCalledWith(
            expect.stringContaining('/items'),
            expect.objectContaining({ title: 'Proof of address' }),
            expect.anything(),
        );
    });

    it('dismisses a suggestion locally without contacting the server', async () => {
        const user = userEvent.setup();
        render(<TemplateItemEditor templateId={1} items={[]} canManage />);

        await generateSuggestions(user, [suggestion()]);

        await user.click(screen.getByRole('button', { name: 'Dismiss' }));

        expect(screen.queryByText('Proof of address')).not.toBeInTheDocument();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('applies every suggestion when "Add all" is used', async () => {
        const user = userEvent.setup();
        render(<TemplateItemEditor templateId={1} items={[]} canManage />);

        await generateSuggestions(user, [
            suggestion({ title: 'Proof of address' }),
            suggestion({ title: 'Passport copy' }),
        ]);

        await user.click(screen.getByRole('button', { name: 'Add all' }));

        await waitFor(() => expect(routerPost).toHaveBeenCalledTimes(2));
        expect(
            screen.queryByText(':count suggestions'),
        ).not.toBeInTheDocument();
    });

    it('toasts when the draft endpoint fails and leaves the trigger actionable', async () => {
        const user = userEvent.setup();
        draftPost.mockRejectedValueOnce(new Error('llm down'));
        render(<TemplateItemEditor templateId={1} items={[]} canManage />);

        await user.type(
            screen.getByLabelText('Suggest items with AI'),
            'Onboarding a new client',
        );
        await user.click(screen.getByRole('button', { name: 'Suggest items' }));

        await waitFor(() =>
            expect(toastError).toHaveBeenCalledWith(
                'Could not generate suggestions. Please try again.',
            ),
        );
        expect(
            screen.getByRole('button', { name: 'Suggest items' }),
        ).toBeEnabled();
        expect(
            screen.queryByText(':count suggestions'),
        ).not.toBeInTheDocument();
    });

    it('toasts partial failure when Add all hits an error mid-sequence', async () => {
        const user = userEvent.setup();
        render(<TemplateItemEditor templateId={1} items={[]} canManage />);

        await generateSuggestions(user, [
            suggestion({ title: 'Proof of address' }),
            suggestion({ title: 'Passport copy' }),
        ]);

        routerPost
            .mockImplementationOnce((_url, _data, options) => {
                options?.onSuccess?.();
            })
            .mockImplementationOnce((_url, _data, options) => {
                options?.onError?.();
            });

        await user.click(screen.getByRole('button', { name: 'Add all' }));

        await waitFor(() =>
            expect(toastError).toHaveBeenCalledWith(
                'Could not add :count of the suggestions. The rest were applied.',
            ),
        );
        expect(routerPost).toHaveBeenCalledTimes(2);
        expect(screen.getByText('Passport copy')).toBeInTheDocument();
    });
});
