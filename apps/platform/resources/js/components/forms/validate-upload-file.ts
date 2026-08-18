import type { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/format-bytes';

type Translate = ReturnType<typeof useTranslation>['t'];

/**
 * Shared client-side size check for FileDropzone and MultiFileDropzone.
 * Returns a translated error message, or null when the file is within bounds.
 */
export function validateFileSize(
    file: File,
    maxBytes: number,
    t: Translate,
): string | null {
    if (file.size > maxBytes) {
        return t('File is too large. Maximum size is :size.', {
            size: formatBytes(maxBytes),
        });
    }

    return null;
}

/** Short type label for an attachment description (e.g. PDF · 1.2 MB). */
export function fileTypeLabel(file: File): string {
    const extension = file.name.split('.').pop()?.toUpperCase();

    return extension && extension.length <= 5 ? extension : 'FILE';
}
