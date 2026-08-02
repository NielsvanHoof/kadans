import { Form } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { useState } from 'react';
import {
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

type ConfirmDestroyDialogBase = {
    title: string;
    description: string;
    confirmLabel: string;
    /**
     * Trigger control (usually a Button). AlertDialogTrigger is the root —
     * pass the trigger as a child, not via Radix `asChild`.
     * Omit when driving the dialog with `open` / `onOpenChange` instead.
     */
    trigger?: ReactNode;
    children?: ReactNode;
    confirmTestId?: string;
    confirmVariant?: 'destructive' | 'default';
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

type ConfirmDestroyFormProps = ConfirmDestroyDialogBase & {
    /** Wayfinder `Controller.destroy.form(...)` props (without children). */
    form: Omit<FormProps, 'children' | 'options'>;
    options?: FormProps['options'];
    onConfirm?: never;
};

type ConfirmDestroyCallbackProps = ConfirmDestroyDialogBase & {
    form?: never;
    options?: never;
    /** Island-style destroy — no Inertia Form / full-page reload. */
    onConfirm: () => void | Promise<void>;
};

type ConfirmDestroyDialogProps =
    ConfirmDestroyFormProps | ConfirmDestroyCallbackProps;

/**
 * Shared confirmation alert for destructive actions.
 * Prefer `form` for classic Inertia deletes; use `onConfirm` for JSON islands.
 */
export default function ConfirmDestroyDialog(props: ConfirmDestroyDialogProps) {
    const {
        title,
        description,
        confirmLabel,
        trigger,
        children,
        confirmTestId,
        confirmVariant = 'destructive',
        open,
        onOpenChange,
    } = props;
    const { t } = useTranslation();
    const [callbackProcessing, setCallbackProcessing] = useState(false);
    // Uncontrolled callback mode still needs a closer after async confirm.
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const isControlled = open !== undefined;
    const dialogOpen = isControlled ? open : uncontrolledOpen;
    const setDialogOpen = (next: boolean) => {
        if (isControlled) {
            onOpenChange?.(next);
        } else {
            setUncontrolledOpen(next);
        }
    };

    return (
        /*
         * React Aria inverts the compound shape: AlertDialogTrigger is the
         * root, and AlertDialogContent *is* the modal (it renders overlay +
         * modal + alertdialog). Wrapping the content in another AlertDialog —
         * the Radix shape — mounts two nested modals, which renders an empty
         * outer panel and warns that the dialog has no title.
         */
        <AlertDialogTrigger
            isOpen={isControlled || 'onConfirm' in props ? dialogOpen : open}
            onOpenChange={
                isControlled || 'onConfirm' in props
                    ? setDialogOpen
                    : onOpenChange
            }
        >
            {trigger}
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                {'onConfirm' in props && props.onConfirm ? (
                    <>
                        {children}
                        <AlertDialogFooter>
                            <AlertDialogCancel variant="secondary">
                                {t('Cancel')}
                            </AlertDialogCancel>
                            <AlertDialogAction
                                type="button"
                                variant={confirmVariant}
                                isDisabled={callbackProcessing}
                                data-test={confirmTestId}
                                onPress={async () => {
                                    // AlertDialogAction does not auto-close;
                                    // wait for the island mutation, then dismiss.
                                    setCallbackProcessing(true);

                                    try {
                                        await props.onConfirm();
                                        setDialogOpen(false);
                                    } finally {
                                        setCallbackProcessing(false);
                                    }
                                }}
                            >
                                {confirmLabel}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </>
                ) : 'form' in props && props.form ? (
                    <Form
                        {...props.form}
                        options={props.options}
                        onSuccess={(...args) => {
                            setDialogOpen(false);
                            props.form.onSuccess?.(...args);
                        }}
                    >
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
                                        isDisabled={processing}
                                        data-test={confirmTestId}
                                    >
                                        {confirmLabel}
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </>
                        )}
                    </Form>
                ) : null}
            </AlertDialogContent>
        </AlertDialogTrigger>
    );
}
