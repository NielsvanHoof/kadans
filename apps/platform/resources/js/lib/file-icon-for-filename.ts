import type { LucideIcon } from 'lucide-react';
import {
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileType,
} from 'lucide-react';

/**
 * Pick a file icon from the filename extension for media lists.
 */
export function fileIconForFilename(filename: string): LucideIcon {
    const extension = filename.split('.').pop()?.toLowerCase() ?? '';

    if (
        ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'].includes(extension)
    ) {
        return FileImage;
    }

    if (['pdf'].includes(extension)) {
        return FileText;
    }

    if (['doc', 'docx', 'txt', 'rtf'].includes(extension)) {
        return FileType;
    }

    if (['xls', 'xlsx', 'csv'].includes(extension)) {
        return FileSpreadsheet;
    }

    return File;
}
