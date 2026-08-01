/**
 * Global shapes for framework classes referenced by generated Data types.
 *
 * The generated app.d.ts lives in a global namespace and cannot import modules,
 * so anything it references from vendor code has to be declared globally here
 * and wired up via `replaceType()` in TypeScriptTransformerServiceProvider.
 */

/**
 * Illuminate\Pagination\LengthAwarePaginator as serialised to Inertia.
 *
 * TKey mirrors the PHP generic (always int) and is unused on the TS side.
 */
declare type LaravelPaginated<TKey, TValue> = {
    data: TValue[];
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
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
};
