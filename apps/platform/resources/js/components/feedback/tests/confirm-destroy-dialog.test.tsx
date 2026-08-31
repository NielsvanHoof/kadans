import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import { Button } from '@/components/ui/button';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        locale: 'en',
        availableLocales: [],
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        onSuccess,
    }: {
        children: (props: { processing: boolean }) => React.ReactNode;
        onSuccess?: () => void;
    }) => (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSuccess?.();
            }}
        >
            {children({ processing: false })}
        </form>
    ),
}));

const { default: ConfirmDestroyDialog } =
    await import('@/components/feedback/confirm-destroy-dialog');

describe('ConfirmDestroyDialog', () => {
    it('opens from the trigger in uncontrolled callback mode and closes after confirm', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn().mockResolvedValue(undefined);

        render(
            <ConfirmDestroyDialog
                title="Delete item"
                description="This cannot be undone."
                confirmLabel="Delete"
                trigger={<Button>Open destroy</Button>}
                onConfirm={onConfirm}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Open destroy' }));

        expect(
            await screen.findByRole('alertdialog', { name: 'Delete item' }),
        ).toBeInTheDocument();
        expect(screen.getByText('This cannot be undone.')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Delete' }));

        await waitFor(() => {
            expect(onConfirm).toHaveBeenCalledOnce();
        });

        await waitFor(() => {
            expect(
                screen.queryByRole('alertdialog', { name: 'Delete item' }),
            ).not.toBeInTheDocument();
        });
    });

    it('uses controlled open state and reports onOpenChange', async () => {
        const user = userEvent.setup();
        const onOpenChange = vi.fn();
        const onConfirm = vi.fn().mockResolvedValue(undefined);

        const { rerender } = render(
            <ConfirmDestroyDialog
                title="Delete item"
                description="Gone forever."
                confirmLabel="Delete"
                open={false}
                onOpenChange={onOpenChange}
                onConfirm={onConfirm}
            />,
        );

        expect(
            screen.queryByRole('alertdialog', { name: 'Delete item' }),
        ).not.toBeInTheDocument();

        rerender(
            <ConfirmDestroyDialog
                title="Delete item"
                description="Gone forever."
                confirmLabel="Delete"
                open={true}
                onOpenChange={onOpenChange}
                onConfirm={onConfirm}
            />,
        );

        expect(
            await screen.findByRole('alertdialog', { name: 'Delete item' }),
        ).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('submits via Inertia Form and closes on success', async () => {
        const user = userEvent.setup();
        const formOnSuccess = vi.fn();

        render(
            <ConfirmDestroyDialog
                title="Delete role"
                description="Remove this role?"
                confirmLabel="Delete"
                trigger={<Button>Open form destroy</Button>}
                form={{
                    action: '/roles/1',
                    method: 'delete',
                    onSuccess: formOnSuccess,
                }}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Open form destroy' }),
        );

        expect(
            await screen.findByRole('alertdialog', { name: 'Delete role' }),
        ).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Delete' }));

        await waitFor(() => {
            expect(formOnSuccess).toHaveBeenCalledOnce();
        });

        await waitFor(() => {
            expect(
                screen.queryByRole('alertdialog', { name: 'Delete role' }),
            ).not.toBeInTheDocument();
        });
    });
});
