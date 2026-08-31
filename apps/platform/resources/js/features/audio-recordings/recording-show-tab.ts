/**
 * Panels of the recording show page, synced to ?tab= so Calendar can be
 * linked to and survives an attach/unlink reload.
 *
 * Transcript is first: reading is the primary job of this page.
 */
export const RECORDING_SHOW_TABS = ['transcript', 'calendar'] as const;

export type RecordingShowTab = (typeof RECORDING_SHOW_TABS)[number];

export function isRecordingShowTab(value: string): value is RecordingShowTab {
    return RECORDING_SHOW_TABS.includes(value as RecordingShowTab);
}
