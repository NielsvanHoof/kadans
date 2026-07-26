import { Form } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';

type FormProps = ComponentProps<typeof Form>;

type ConfirmDestroyDialogProps = {
    title: string;
    description: string;
    confirmLabel: string;
    /**
     * Trigger control (usually a Button). Wrapped with DialogTrigger asChild.
     * Omit when driving the dialog with `open` / `onOpenChange` instead.
     */
    trigger?: ReactNode;
    /** Wayfinder `Controller.destroy.form(...)` props (without children). */
    form: Omit<FormProps, 'children' | 'options'>;
    options?: FormProps['options'];
    /** Extra fields rendered inside the form (e.g. hidden inputs). */
    children?: ReactNode;
    confirmTestId?: string;
    confirmVariant?: 'destructive' | 'default';
    /** Controlled open state — use with dropdown menu items that open a dialog. */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

/**
 * Shared confirmation dialog for destructive Inertia Form actions.
 * Mirrors the archive-dossier / delete-user Dialog pattern.
 */
export default function ConfirmDestroyDialog({
    title,
    description,
    confirmLabel,
    trigger,
    form,
    options,
    children,
    confirmTestId,
    confirmVariant = 'destructive',
    open,
    onOpenChange,
}: ConfirmDestroyDialogProps) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {trigger ? <DialogTrigger asChild>{trigger}</DialogTrigger> : null}
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>

                <Form {...form} options={options}>
                    {({ processing }) => (
                        <>
                            {children}
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        {t('Cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant={confirmVariant}
                                    disabled={processing}
                                    data-test={confirmTestId}
                                >
                                    {confirmLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
