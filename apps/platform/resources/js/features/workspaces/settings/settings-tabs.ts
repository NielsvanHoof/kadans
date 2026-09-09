/**
 * Which tab each field belongs to.
 *
 * The policy tabs share one form and one save button, so a validation error can
 * land on a panel the reader cannot see. Without this map a failed save looks
 * like a button that did nothing.
 */
export const SETTINGS_TAB_FIELDS = {
    security: ['two_factor_enforcement'],
    portal: ['portal_session_idle_minutes', 'portal_session_absolute_minutes'],
    reminders: ['default_reminder_interval_days'],
    retention: ['retention_archived_days', 'retention_activity_log_days'],
    assisted: [
        'ai_document_overview_enabled',
        'ai_checklist_draft_enabled',
        'ai_reminder_draft_enabled',
        'ai_document_search_enabled',
    ],
} as const;

export type SettingsTab = keyof typeof SETTINGS_TAB_FIELDS | 'details';

/** Tab order, so "the first tab with an error" matches what the reader sees. */
export const POLICY_TABS = [
    'security',
    'portal',
    'reminders',
    'retention',
    'assisted',
] as const satisfies readonly (keyof typeof SETTINGS_TAB_FIELDS)[];

export function tabHasError(
    tab: keyof typeof SETTINGS_TAB_FIELDS,
    errors: Record<string, string>,
): boolean {
    return SETTINGS_TAB_FIELDS[tab].some((field) => Boolean(errors[field]));
}

export function firstTabWithError(
    errors: Record<string, string>,
): SettingsTab | null {
    return POLICY_TABS.find((tab) => tabHasError(tab, errors)) ?? null;
}

/**
 * Hides a force-mounted panel that is not the selected one.
 *
 * React Aria marks those panels `inert`, which takes them out of the
 * accessibility tree and out of the tab order but leaves them on screen — so
 * without this every panel renders stacked and the tabs do nothing. `hidden`
 * is display:none, which still submits the fields inside it; only `disabled`
 * would drop them from the form.
 */
export const INACTIVE_PANEL_CLASS = 'data-[inert]:hidden';
