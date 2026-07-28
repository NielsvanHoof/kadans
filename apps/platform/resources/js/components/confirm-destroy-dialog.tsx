import { Form } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { useTranslation } from '@/hooks/use-translation';

type FormProps = ComponentProps<typeof Form>;

type ConfirmDestroyDialogProps = {
    title: string;
    description: string;
    confirmLabel: string;
    /**
     * Trigger control (usually a Button). Wrapped with AlertDialogTrigger asChild.
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
 * Shared confirmation alert for destructive Inertia Form actions.
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
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            {trigger ? (
                <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>
            ) : null}
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <Form {...form} options={options}>
                    {({ processing }) => (
                        <>
                            {children}
                            <AlertDialogFooter>
                                <AlertDialogCancel variant="secondary">
                                    {t('Cancel')}
                                </AlertDialogCancel>
                                <AlertDialogAction
                                    type="submit"
                                    variant={confirmVariant}
                                    disabled={processing}
                                    data-test={confirmTestId}
                                >
                                    {confirmLabel}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
