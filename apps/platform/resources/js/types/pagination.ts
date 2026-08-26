/**
 * Laravel LengthAwarePaginator shape as serialized to Inertia (flat toArray()).
 */
export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    path: string;
    first_page_url: string | null;
    last_page_url: string | null;
    next_page_url: string | null;
    prev_page_url: string | null;
    links: PaginationLink[];
};

/**
 * Laravel Paginator (simplePaginate) as serialized to Inertia.
 *
 * No total or last_page — those require COUNT(*) of the filtered set.
 */
export type SimplePaginated<T> = {
    data: T[];
    current_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    path: string;
    first_page_url: string | null;
    next_page_url: string | null;
    prev_page_url: string | null;
};

export type IndexQueryFilterOption = {
    value: string;
    label: string;
    /** Optional section header for long option lists (e.g. audit event domain). */
    group?: string;
};

export type IndexQueryFilter = {
    key: string;
    type: 'search' | 'select';
    label: string;
    options?: IndexQueryFilterOption[];
};

export type IndexQuerySort = {
    key: string;
    label: string;
};

export type IndexQueryConfig = {
    filters: IndexQueryFilter[];
    sorts: IndexQuerySort[];
    defaults: {
        sort: string;
    };
};
