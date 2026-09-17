import { useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import type { DocumentScanState } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { fileIconForFilename } from '@/lib/file-icon-for-filename';

/**
 * The rendered first page of a document, with the file-type icon as fallback.
 *
 * Most documents will not have one, and that is normal rather than exceptional:
 * the check may still be running, the verdict may not have been clean, or the
 * format may have no preview at all. So the icon is the base case and the
 * picture is the enhancement, not the other way round.
 *
 * A thumbnail can also 404 between the page rendering and the image loading —
 * a document deleted in another tab, or a re-check that withdrew the preview —
 * so a failed load falls back rather than leaving a broken image in the list.
 */
export default function DocumentThumbnail({
    filename,
    thumbnailUrl,
    scanState,
}: {
    filename: string;
    thumbnailUrl: string | null;
    scanState: DocumentScanState;
}) {
    const { t } = useTranslation();
    const [failed, setFailed] = useState(false);

    if (scanState === 'scanning') {
        return (
            <Skeleton
                className="size-12 shrink-0 rounded-md"
                aria-label={t('Running security check')}
            />
        );
    }

    if (thumbnailUrl === null || failed) {
        const FileIcon = fileIconForFilename(filename);

        return (
            <div className="bg-muted flex size-12 shrink-0 items-center justify-center rounded-md">
                <FileIcon
                    className="text-muted-foreground size-5"
                    aria-hidden
                />
            </div>
        );
    }

    return (
        <img
            src={thumbnailUrl}
            /*
             * Empty alt, not a description: the filename sits next to this in
             * the same row, so announcing it again makes a screen reader read
             * every document twice.
             */
            alt=""
            loading="lazy"
            decoding="async"
            width={48}
            height={48}
            className="bg-muted size-12 shrink-0 rounded-md border object-cover"
            onError={() => setFailed(true)}
        />
    );
}
