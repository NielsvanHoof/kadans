import {
    DEFAULT_TEMPLATE_LIBRARY_TAB,
    isTemplateLibraryTab,
    type TemplateLibraryTab,
} from '@/features/questionnaires/list/template-library-tab';
import { useUrlTab } from '@/hooks/use-url-tab';

/**
 * Keeps the active template library tab in sync with ?tab=system|custom.
 */
export function useTemplateLibraryTab() {
    return useUrlTab<TemplateLibraryTab>(
        'tab',
        isTemplateLibraryTab,
        DEFAULT_TEMPLATE_LIBRARY_TAB,
    );
}
