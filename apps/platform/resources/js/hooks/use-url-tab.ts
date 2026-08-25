import { useCallback, useEffect, useState } from 'react';

/**
 * Keeps an active tab/panel key in sync with a query parameter.
 *
 * The URL is read exactly once, in the `useState` initialiser, and written back
 * with `history.replaceState`. That asymmetry is deliberate and load-bearing:
 * a `replaceState` never reaches the server, so Laravel's `url()->previous()`
 * has no `?tab=`, and a validation redirect back therefore lands on the bare
 * path. Re-deriving the key from the URL on every render would silently reset
 * the panel — and unmount any form inside it — on exactly the redirect whose
 * inline errors the user needs to read (see TASK-42 / TASK-44).
 */
export function useUrlTab<T extends string>(
    param: string,
    isTab: (value: string) => value is T,
    fallback: T,
) {
    const readFromUrl = useCallback((): T | null => {
        if (typeof window === 'undefined') {
            return null;
        }

        const value = new URLSearchParams(window.location.search).get(param);

        return value !== null && isTab(value) ? value : null;
    }, [isTab, param]);

    const writeToUrl = useCallback(
        (next: T): void => {
            if (typeof window === 'undefined') {
                return;
            }

            const url = new URL(window.location.href);
            url.searchParams.set(param, next);
            window.history.replaceState({}, '', url);
        },
        [param],
    );

    const [tab, setTabState] = useState<T>(() => readFromUrl() ?? fallback);

    // Seed the URL on first mount when the parameter is not present yet.
    useEffect(() => {
        if (readFromUrl() === null) {
            writeToUrl(tab);
        }
    }, [readFromUrl, tab, writeToUrl]);

    const setTab = useCallback(
        (next: T) => {
            setTabState(next);
            writeToUrl(next);
        },
        [writeToUrl],
    );

    return [tab, setTab] as const;
}
