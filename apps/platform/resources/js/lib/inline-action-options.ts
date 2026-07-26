const inlineReloadProps: string[] = ['dossier', 'flash'];

/**
 * Partial reload after inline dossier/portal mutations.
 * Keeps scroll position and refreshes dossier + flash toast props.
 */
export const inlineActionOptions = {
    preserveScroll: true,
    only: inlineReloadProps,
};
