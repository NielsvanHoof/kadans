import { useTranslation } from '@/hooks/use-translation';

const IMAGE_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp']);

/**
 * Infer preview kind from a filename extension.
 */
export function documentPreviewKind(
    filename: string,
): 'pdf' | 'image' | 'unsupported' {
    const extension = filename.split('.').pop()?.toLowerCase() ?? '';

    if (extension === 'pdf') {
        return 'pdf';
    }

    if (IMAGE_EXTENSIONS.has(extension)) {
        return 'image';
    }

    return 'unsupported';
}

/**
 * Side-by-side document preview (PDF iframe or image) when a preview URL exists.
 * Uses an inline-disposition URL — never the attachment download endpoint.
 */
export default function DocumentPreviewPanel({
    filename,
    previewUrl,
}: {
    filename: string;
    previewUrl: string;
}) {
    const { t } = useTranslation();
    const kind = documentPreviewKind(filename);

    if (kind === 'unsupported') {
        return null;
    }

    return (
        <section
            className="space-y-2"
            aria-labelledby="document-preview-heading"
        >
            <h2
                id="document-preview-heading"
                className="text-lg font-semibold tracking-tight"
            >
                {t('Document preview')}
            </h2>
            <div className="overflow-hidden rounded-md border bg-muted/20">
                {kind === 'pdf' ? (
                    <iframe
                        src={previewUrl}
                        title={filename}
                        className="h-[min(70vh,720px)] w-full bg-background"
                    />
                ) : (
                    <img
                        src={previewUrl}
                        alt={filename}
                        className="mx-auto max-h-[min(70vh,720px)] w-full object-contain"
                    />
                )}
            </div>
        </section>
    );
}
