import { type ComponentProps, useId } from 'react';
import { FieldError } from '@/components/ui/field';

/**
 * Validation error text. Announced to assistive tech when it appears.
 * Pass a stable `id` (or use the generated one) with the field’s
 * `aria-describedby` and set `aria-invalid` on the control.
 */
export default function InputError({
    message,
    id,
    ...props
}: ComponentProps<typeof FieldError> & { message?: string }) {
    const generatedId = useId();
    const errorId = id ?? generatedId;

    if (!message) {
        return null;
    }

    return (
        <FieldError id={errorId} aria-live="polite" {...props}>
            {message}
        </FieldError>
    );
}

/**
 * ARIA props that connect a control to an InputError with a known id.
 */
export function fieldErrorAriaProps(
    errorId: string,
    message?: string,
): {
    'aria-invalid'?: true;
    'aria-describedby'?: string;
} {
    if (!message) {
        return {};
    }

    return {
        'aria-invalid': true,
        'aria-describedby': errorId,
    };
}
