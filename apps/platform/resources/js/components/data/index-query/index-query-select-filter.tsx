import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxLabel,
    ComboboxList,
} from '@/components/ui/combobox';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import type { IndexQueryFilterOption } from '@/types/pagination';

const ALL_VALUE = '__all__';

/** Option lists this size or larger get a searchable combobox instead of a select. */
const SEARCHABLE_OPTION_THRESHOLD = 12;

type OptionGroup = {
    label: string | null;
    options: IndexQueryFilterOption[];
};

/**
 * Build grouped sections from optional `group` metadata.
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
 * Index-query select filter: plain select for short lists, searchable combobox
 * for long ones (activity events, many actors, …).
 *
 * The combobox owns its own typeahead. RAC filters the static collection with
 * `defaultFilter` (contains) across group sections, so there is no React-side
 * query state and nothing has to stop keystrokes reaching the listbox.
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
    const isSearchable = options.length >= SEARCHABLE_OPTION_THRESHOLD;
    const fieldLabel = t(label);

    if (isSearchable) {
        const optionGroups = groupFilterOptions(options);

        return (
            <div className="flex min-w-40 flex-col gap-1.5">
                <span className="text-muted-foreground text-xs font-medium">
                    {fieldLabel}
                </span>
                <Combobox
                    selectedKey={selectedKey}
                    // A cleared input must not clear the filter — fall back to
                    // "All" so the toolbar always has a defined selection.
                    onSelectionChange={(value) =>
                        onSelectionChange(
                            value === null ? ALL_VALUE : String(value),
                        )
                    }
                    // Required for the popover to stay open (and show the empty
                    // copy) once the typeahead filters everything out.
                    allowsEmptyCollection
                    aria-label={fieldLabel}
                    className="w-full min-w-40"
                >
                    <ComboboxInput
                        placeholder={fieldLabel}
                        aria-label={fieldLabel}
                        // The input carries the current selection's label, so
                        // select it on focus — otherwise typing appends to
                        // "All" instead of replacing it.
                        onFocus={(event) => event.target.select()}
                    />
                    <ComboboxContent className="max-w-sm min-w-72">
                        <ComboboxList
                            renderEmptyState={() => (
                                <ComboboxEmpty>
                                    {t('No matching options.')}
                                </ComboboxEmpty>
                            )}
                        >
                            <ComboboxGroup>
                                <ComboboxItem
                                    id={ALL_VALUE}
                                    textValue={t('All')}
                                >
                                    {t('All')}
                                </ComboboxItem>
                            </ComboboxGroup>
                            {optionGroups.map((group) => (
                                <ComboboxGroup
                                    key={group.label ?? '__ungrouped__'}
                                >
                                    {group.label ? (
                                        <ComboboxLabel>
                                            {t(group.label)}
                                        </ComboboxLabel>
                                    ) : null}
                                    {group.options.map((option) => {
                                        const optionLabel = t(option.label);

                                        return (
                                            <ComboboxItem
                                                key={option.value}
                                                id={option.value}
                                                textValue={optionLabel}
                                            >
                                                {optionLabel}
                                            </ComboboxItem>
                                        );
                                    })}
                                </ComboboxGroup>
                            ))}
                        </ComboboxList>
                    </ComboboxContent>
                </Combobox>
            </div>
        );
    }

    return (
        <div className="flex min-w-40 flex-col gap-1.5">
            <span className="text-muted-foreground text-xs font-medium">
                {fieldLabel}
            </span>
            <Select
                selectedKey={selectedKey}
                onSelectionChange={(value) => onSelectionChange(String(value))}
                placeholder={fieldLabel}
                aria-label={fieldLabel}
            >
                <SelectTrigger className="w-full min-w-40">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectItem id={ALL_VALUE}>{t('All')}</SelectItem>
                        {options.map((option) => (
                            <SelectItem key={option.value} id={option.value}>
                                {t(option.label)}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>
    );
}

export { ALL_VALUE };
