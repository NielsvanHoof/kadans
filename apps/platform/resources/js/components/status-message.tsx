import type { ReactNode } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';

type StatusMessageProps = {
    children: ReactNode;
    className?: string;
    variant?: 'success';
};

/**
 * Inline feedback banner for success states, using theme semantic tokens.
 */
export default function StatusMessage({
    children,
    className,
    variant = 'success',
}: StatusMessageProps) {
    return (
        <Alert role="status" variant={variant} className={className}>
            <AlertDescription className="font-medium">
                {children}
            </AlertDescription>
        </Alert>
    );
}
