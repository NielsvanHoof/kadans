import { SlidersHorizontal } from 'lucide-react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { useDebounceCallback } from 'usehooks-ts';
import IndexQuerySelectFilter, {
    ALL_VALUE,
} from '@/components/data/index-query/index-query-select-filter';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { useIsMobile } from '@/hooks/use-mobile';
import { useTranslation } from '@/hooks/use-translation';
import type { IndexQueryConfig } from '@/types/pagination';

const SEARCH_DEBOUNCE_MS = 500;

/** Hard cap for index search fields — enough for real queries, not dump pastes. */
const SEARCH_MAX_LENGTH = 255;

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
    const isMobile = useIsMobile();
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
                // Clamp URL values too — someone can share a ?q=… longer than the cap.
                initial[filter.key] = (filters[filter.key] ?? '').slice(
                    0,
                    SEARCH_MAX_LENGTH,
                );
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
                    const value = (filters[filter.key] ?? '').slice(
                        0,
                        SEARCH_MAX_LENGTH,
                    );

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
        // maxLength covers typing; slice covers paste into browsers that ignore it.
        const clipped = value.slice(0, SEARCH_MAX_LENGTH);

        setDrafts((prev) => {
            const nextDrafts = { ...prev, [key]: clipped };
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

    // The first search field is the one people reach for, so it stays visible
    // on mobile; everything else goes behind the disclosure.
    const primarySearch = config.filters.find(
        (filter) => filter.type === 'search',
    );
    const collapsibleFilters = config.filters.filter(
        (filter) => filter !== primarySearch,
    );
    const activeCollapsedCount =
        collapsibleFilters.filter((filter) => Boolean(filters[filter.key]))
            .length + (sort !== null && sort !== config.defaults.sort ? 1 : 0);

    function renderFilter(filter: IndexQueryConfig['filters'][number]) {
        if (filter.type === 'search') {
            return (
                <div
                    key={filter.key}
                    className="flex min-w-48 flex-1 flex-col gap-1.5"
                >
                    <label
                        htmlFor={`index-filter-${filter.key}`}
                        className="text-muted-foreground text-xs font-medium"
                    >
                        {t(filter.label)}
                    </label>
                    <Input
                        id={`index-filter-${filter.key}`}
                        type="search"
                        value={drafts[filter.key] ?? ''}
                        maxLength={SEARCH_MAX_LENGTH}
                        placeholder={t(filter.label)}
                        onFocus={handleSearchFocus}
                        onBlur={handleSearchBlur}
                        onChange={(event) =>
                            handleSearchChange(filter.key, event.target.value)
                        }
                    />
                </div>
            );
        }

        return (
            <IndexQuerySelectFilter
                key={filter.key}
                label={filter.label}
                selectedKey={filters[filter.key] ?? ALL_VALUE}
                options={filter.options ?? []}
                onSelectionChange={(value) =>
                    handleSelectChange(filter.key, value)
                }
            />
        );
    }

    const sortControl =
        config.sorts.length > 0 ? (
            <div className="flex min-w-44 flex-col gap-1.5">
                <span className="text-muted-foreground text-xs font-medium">
                    {t('Sort')}
                </span>
                <Select
                    selectedKey={activeSort}
                    onSelectionChange={(value) =>
                        setSort(value === null ? null : String(value))
                    }
                    placeholder={t('Sort')}
                    aria-label={t('Sort')}
                >
                    <SelectTrigger className="w-full min-w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {config.sorts.map((option) => (
                                <SelectItem key={option.key} id={option.key}>
                                    {t(option.label)}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>
        ) : null;

    // Below md: the primary search stays out in the open and everything else
    // folds away. Six controls stacked one per row measured 338px on a 375px
    // screen and pushed the first result to y=689 of an 812px viewport — the
    // filters were the page. Desktop keeps the flat wrapping row unchanged.
    //
    // This is a JS branch rather than CSS because React Aria's DisclosurePanel
    // hides its content with the `hidden` attribute, which a breakpoint cannot
    // undo — the desktop row has to be a genuinely different tree.
    const truncationNotice = config.search_truncated ? (
        <p
            role="status"
            className="text-muted-foreground text-sm text-pretty"
        >
            {t(
                'Search results are limited to the first matches. Narrow your query to see more.',
            )}
        </p>
    ) : null;

    if (isMobile) {
        return (
            <div
                data-slot="index-query-toolbar"
                className="flex flex-col gap-3"
            >
                {truncationNotice}
                {primarySearch ? renderFilter(primarySearch) : null}

                {collapsibleFilters.length > 0 || sortControl ? (
                    <Collapsible>
                        <CollapsibleTrigger
                            variant="outline"
                            size="sm"
                            className="w-full justify-between"
                        >
                            <span className="inline-flex items-center gap-2">
                                <SlidersHorizontal className="size-3.5" />
                                {t('Filters')}
                            </span>
                            {/* The count is what makes collapsing safe: an
                                active filter must never be invisible, or the
                                list looks wrong for no discoverable reason. */}
                            {activeCollapsedCount > 0 ? (
                                <Badge variant="secondary">
                                    {activeCollapsedCount}
                                </Badge>
                            ) : null}
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div className="flex flex-col gap-3 pt-3">
                                {collapsibleFilters.map(renderFilter)}
                                {sortControl}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                ) : null}
            </div>
        );
    }

    return (
        <div data-slot="index-query-toolbar" className="flex flex-col gap-3">
            {truncationNotice}
            <div className="flex flex-wrap items-end gap-3">
                {config.filters.map(renderFilter)}
                {sortControl}
            </div>
        </div>
    );
}
