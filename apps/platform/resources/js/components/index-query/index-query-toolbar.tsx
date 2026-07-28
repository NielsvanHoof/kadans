import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { useDebounceCallback } from 'usehooks-ts';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useIndexQuery } from '@/hooks/use-index-query';
import { useTranslation } from '@/hooks/use-translation';
import type { IndexQueryConfig } from '@/types/pagination';

const ALL_VALUE = '__all__';

const SEARCH_DEBOUNCE_MS = 500;

/**
 * Generic filter + sort toolbar driven by controller-provided indexQuery config.
 * Stays interactive while results reload — pending UI lives on IndexQueryResults.
 */
export default function IndexQueryToolbar({
    config,
    only,
}: {
    config: IndexQueryConfig;
    /** Partial-reload keys for filter/sort visits (page-specific). */
    only?: string[];
}) {
    const { t } = useTranslation();
    const { filters, sort, setFilters, setSort } = useIndexQuery({
        only,
    });

    // True while a search field is focused — blocks URL→draft sync that would
    // clobber characters the user has already typed.
    const searchFocusedRef = useRef(false);

    // Local draft for search inputs so typing doesn't fire a visit on every keystroke.
    const [drafts, setDrafts] = useState<Record<string, string>>(() => {
        const initial: Record<string, string> = {};
        for (const filter of config.filters) {
            if (filter.type === 'search') {
                initial[filter.key] = filters[filter.key] ?? '';
            }
        }
        return initial;
    });

    // Keep drafts in sync when the URL changes (back/forward, clear) — but never
    // while the user is actively typing into a search field.
    useEffect(() => {
        if (searchFocusedRef.current) {
            return;
        }

        setDrafts((prev) => {
            const next = { ...prev };
            let changed = false;

            for (const filter of config.filters) {
                if (filter.type === 'search') {
                    const value = filters[filter.key] ?? '';

                    if (next[filter.key] !== value) {
                        next[filter.key] = value;
                        changed = true;
                    }
                }
            }

            return changed ? next : prev;
        });
    }, [filters, config.filters]);

    // useEffectEvent keeps a stable identity so useDebounceCallback does not
    // recreate (and orphan) a new lodash debounce timer on every keystroke.
    const applySearchDrafts = useEffectEvent(
        (nextDrafts: Record<string, string>) => {
            const nextFilters: Record<string, string> = { ...filters };
            let changed = false;

            for (const filter of config.filters) {
                if (filter.type !== 'search') {
                    continue;
                }
                const draft = nextDrafts[filter.key] ?? '';
                const current = filters[filter.key] ?? '';
                if (draft !== current) {
                    if (draft === '') {
                        delete nextFilters[filter.key];
                    } else {
                        nextFilters[filter.key] = draft;
                    }
                    changed = true;
                }
            }

            if (changed) {
                setFilters(nextFilters);
            }
        },
    );

    const debouncedApplySearchDrafts = useDebounceCallback(
        applySearchDrafts,
        SEARCH_DEBOUNCE_MS,
    );

    function handleSearchChange(key: string, value: string): void {
        setDrafts((prev) => {
            const nextDrafts = { ...prev, [key]: value };
            debouncedApplySearchDrafts(nextDrafts);
            return nextDrafts;
        });
    }

    function handleSearchFocus(): void {
        searchFocusedRef.current = true;
    }

    function handleSearchBlur(): void {
        searchFocusedRef.current = false;
        // Apply the latest draft immediately when leaving the field.
        debouncedApplySearchDrafts.flush();
    }

    function handleSelectChange(key: string, value: string): void {
        const nextFilters: Record<string, string> = { ...filters };
        if (value === ALL_VALUE) {
            delete nextFilters[key];
        } else {
            nextFilters[key] = value;
        }
        setFilters(nextFilters);
    }

    const activeSort = sort ?? config.defaults.sort;

    return (
        <div className="flex flex-wrap items-end gap-3">
            {config.filters.map((filter) => {
                if (filter.type === 'search') {
                    return (
                        <div
                            key={filter.key}
                            className="flex min-w-48 flex-1 flex-col gap-1.5"
                        >
                            <label
                                htmlFor={`index-filter-${filter.key}`}
                                className="text-xs font-medium text-muted-foreground"
                            >
                                {t(filter.label)}
                            </label>
                            <Input
                                id={`index-filter-${filter.key}`}
                                type="search"
                                value={drafts[filter.key] ?? ''}
                                placeholder={t(filter.label)}
                                onFocus={handleSearchFocus}
                                onBlur={handleSearchBlur}
                                onChange={(event) =>
                                    handleSearchChange(
                                        filter.key,
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    );
                }

                return (
                    <div
                        key={filter.key}
                        className="flex min-w-40 flex-col gap-1.5"
                    >
                        <span className="text-xs font-medium text-muted-foreground">
                            {t(filter.label)}
                        </span>
                        <Select
                            value={filters[filter.key] ?? ALL_VALUE}
                            onValueChange={(value) =>
                                handleSelectChange(filter.key, value)
                            }
                        >
                            <SelectTrigger className="w-full min-w-40">
                                <SelectValue placeholder={t(filter.label)} />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectItem value={ALL_VALUE}>
                                        {t('All')}
                                    </SelectItem>
                                    {(filter.options ?? []).map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {t(option.label)}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </div>
                );
            })}

            {config.sorts.length > 0 && (
                <div className="flex min-w-44 flex-col gap-1.5">
                    <span className="text-xs font-medium text-muted-foreground">
                        {t('Sort')}
                    </span>
                    <Select
                        value={activeSort}
                        onValueChange={(value) => setSort(value)}
                    >
                        <SelectTrigger className="w-full min-w-44">
                            <SelectValue placeholder={t('Sort')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {config.sorts.map((option) => (
                                    <SelectItem
                                        key={option.key}
                                        value={option.key}
                                    >
                                        {t(option.label)}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>
            )}
        </div>
    );
}
