import type { ReactNode } from 'react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

export type SegmentedControlOption = {
    value: string;
    label: ReactNode;
    /** Optional leading icon (e.g. Sun / Moon for appearance). */
    icon?: ReactNode;
};

type SegmentedControlProps = {
    /** Accessible name for the group (announced to screen readers). */
    'aria-label': string;
    value: string;
    options: SegmentedControlOption[];
    onValueChange: (value: string) => void;
    className?: string;
};

/**
 * Accessible single-select segmented control built on ToggleGroup.
 * Replaces the duplicated hand-rolled tabs in appearance and locale settings.
 */
export default function SegmentedControl({
    'aria-label': ariaLabel,
    value,
    options,
    onValueChange,
    className,
}: SegmentedControlProps) {
    return (
        <ToggleGroup
            type="single"
            value={value}
            onValueChange={(next) => {
                // Radix clears the value when re-clicking the active item — ignore that.
                if (next) {
                    onValueChange(next);
                }
            }}
            aria-label={ariaLabel}
            className={cn(
                'inline-flex gap-1 rounded-lg bg-muted p-1',
                className,
            )}
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    aria-label={
                        typeof option.label === 'string'
                            ? option.label
                            : option.value
                    }
                    className={cn(
                        'flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-sm shadow-none',
                        'data-[state=on]:bg-background data-[state=on]:text-foreground data-[state=on]:shadow-xs',
                        'data-[state=off]:text-muted-foreground data-[state=off]:hover:bg-muted/80 data-[state=off]:hover:text-foreground',
                    )}
                >
                    {option.icon}
                    <span>{option.label}</span>
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
