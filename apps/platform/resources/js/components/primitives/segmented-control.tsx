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
            selectionMode="single"
            selectedKeys={[value]}
            onSelectionChange={(keys) => {
                // React Aria clears the selection when re-clicking the active
                // item — keep the current value rather than emitting empty.
                const [next] = Array.from(keys);
                if (next !== undefined) {
                    onValueChange(String(next));
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
                    id={option.value}
                    aria-label={
                        typeof option.label === 'string'
                            ? option.label
                            : option.value
                    }
                    className={cn(
                        'flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-sm shadow-none',
                        'text-muted-foreground hover:bg-muted/80 hover:text-foreground',
                        'data-selected:bg-background data-selected:text-foreground data-selected:shadow-xs',
                    )}
                >
                    {option.icon}
                    <span>{option.label}</span>
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
