import { useState } from 'react';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectList,
    SelectPopover,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import type { IndexQueryFilterOption } from '@/types/pagination';

const ALL_VALUE = '__all__';

/** Option lists this size or larger get an in-popover typeahead. */
const SEARCHABLE_OPTION_THRESHOLD = 12;

type OptionGroup = {
    label: string | null;
    options: IndexQueryFilterOption[];
};

/**
 * Build SelectGroup sections from optional `group` metadata.
 * Ungrouped options (and the list when no groups exist) use a single null section.
 */
function groupFilterOptions(options: IndexQueryFilterOption[]): OptionGroup[] {
    if (options.length === 0) {
        return [];
    }

    const hasGroups = options.some((option) => option.group);

    if (!hasGroups) {
        return [{ label: null, options }];
    }

    const byGroup = new Map<string, IndexQueryFilterOption[]>();

    for (const option of options) {
        const groupLabel = option.group ?? '';
        const bucket = byGroup.get(groupLabel) ?? [];
        bucket.push(option);
        byGroup.set(groupLabel, bucket);
    }

    // Drop empty buckets so RAC does not render blank group sections.
    return [...byGroup.entries()]
        .map(([label, groupOptions]) => ({
            label: label === '' ? null : label,
            options: groupOptions,
        }))
        .filter((group) => group.options.length > 0);
}

/**
 * Index-query select filter: plain select for short lists, searchable +
 * optionally grouped for long lists (activity events, many actors, …).
 *
 * Searchable mode filters options in React (not RAC Autocomplete) so group
 * headers and empty copy stay predictable as the list grows.
 */
export default function IndexQuerySelectFilter({
    label,
    selectedKey,
    options,
    onSelectionChange,
}: {
    label: string;
    selectedKey: string;
    options: IndexQueryFilterOption[];
    onSelectionChange: (value: string) => void;
}) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const isSearchable = options.length >= SEARCHABLE_OPTION_THRESHOLD;

    // Match against translated labels so locale-specific typing works.
    const needle = query.trim().toLocaleLowerCase();
    const filteredOptions =
        needle === ''
            ? options
            : options.filter((option) =>
                  t(option.label).toLocaleLowerCase().includes(needle),
              );

    const optionGroups = groupFilterOptions(filteredOptions);

    const optionItems = optionGroups.map((group) => (
        <SelectGroup key={group.label ?? '__ungrouped__'}>
            {group.label ? <SelectLabel>{t(group.label)}</SelectLabel> : null}
            {group.options.map((option) => {
                const optionLabel = t(option.label);

                return (
                    <SelectItem
                        key={option.value}
                        id={option.value}
                        textValue={optionLabel}
                    >
                        {optionLabel}
                    </SelectItem>
                );
            })}
        </SelectGroup>
    ));

    return (
        <div className="flex min-w-40 flex-col gap-1.5">
            <span className="text-xs font-medium text-muted-foreground">
                {t(label)}
            </span>
            <Select
                selectedKey={selectedKey}
                onSelectionChange={(value) => onSelectionChange(String(value))}
                // Clear the typeahead when the popover closes so the next open
                // shows the full list again.
                onOpenChange={(open) => {
                    if (!open) {
                        setQuery('');
                    }
                }}
                placeholder={t(label)}
                aria-label={t(label)}
            >
                <SelectTrigger className="w-full min-w-40">
                    <SelectValue />
                </SelectTrigger>

                {isSearchable ? (
                    // SelectContent always wraps children in SelectList, so
                    // searchable filters compose Popover + search + List manually.
                    <SelectPopover className="max-w-sm min-w-72">
                        <div className="p-1 pb-0">
                            <Input
                                type="search"
                                value={query}
                                autoFocus
                                spellCheck={false}
                                autoComplete="off"
                                placeholder={t('Search :label…', {
                                    label: t(label),
                                })}
                                aria-label={t('Search :label…', {
                                    label: t(label),
                                })}
                                // Keep Select from treating keystrokes as its
                                // own typeahead while the user filters options.
                                onKeyDown={(event) => event.stopPropagation()}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                        </div>
                        <SelectList>
                            <SelectGroup>
                                <SelectItem id={ALL_VALUE} textValue={t('All')}>
                                    {t('All')}
                                </SelectItem>
                            </SelectGroup>
                            {optionItems}
                        </SelectList>
                        {/*
                          Empty copy sits outside SelectList — RAC strips
                          non-collection children inside the listbox.
                        */}
                        {filteredOptions.length === 0 ? (
                            <div
                                data-slot="select-empty"
                                className="flex w-full justify-center px-2 py-2 text-center text-xs/relaxed text-muted-foreground"
                            >
                                {t('No matching options.')}
                            </div>
                        ) : null}
                    </SelectPopover>
                ) : (
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem id={ALL_VALUE}>{t('All')}</SelectItem>
                            {options.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    id={option.value}
                                >
                                    {t(option.label)}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                )}
            </Select>
        </div>
    );
}

export { ALL_VALUE };
