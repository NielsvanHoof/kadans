import { useEffect, useState } from 'react';
import UploadedDocumentController from '@/actions/App/Dossiers/Http/Controllers/UploadedDocuments/UploadedDocumentController';
import ErrorState from '@/components/primitives/error-state';
import Heading from '@/components/primitives/heading';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants, LinkButton } from '@/components/ui/button';
import DocumentExtractionView from '@/features/document-requests/staff/review/document-extraction-view';
import DocumentPreviewPanel, {
    documentPreviewKind,
} from '@/features/document-requests/staff/review/document-preview-panel';
import { ocrRunningBannerMessage } from '@/features/document-requests/staff/review/ocr-processing-copy';
import type { UploadedDocumentShowProps } from '@/features/document-requests/types';
import { useFetchOnPipelineUpdated } from '@/features/pipelines/hooks/use-fetch-on-pipeline-updated';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/format-bytes';
import { show as showDossier } from '@/routes/workspaces/dossiers';

type UploadedDocumentDetail = UploadedDocumentShowProps['uploaded_document'];

/**
 * OCR extraction body for a single uploaded document.
 * Refreshes via Reverb + JSON island when the OCR pipeline advances.
 */
export default function UploadedDocumentShowContent({
    uploaded_document: initialUploadedDocument,
    document_request: documentRequest,
    dossier,
    can_download: canDownload,
}: UploadedDocumentShowProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();
    const [uploadedDocument, setUploadedDocument] = useState(
        initialUploadedDocument,
    );

    // Sync when Inertia replaces the page prop (navigation / password confirm).
    useEffect(() => {
        setUploadedDocument(initialUploadedDocument);
    }, [initialUploadedDocument]);

    const isProcessing =
        uploadedDocument.processing_status === 'pending' ||
        uploadedDocument.processing_status === 'processing';

    useFetchOnPipelineUpdated<{ uploaded_document: UploadedDocumentDetail }>({
        enabled: isProcessing,
        // The dossier is optional on this page, so fall back to the
        // firm-wide stream rather than going dark when it is absent.
        scope: dossier
            ? { on: 'dossier', dossierId: dossier.id }
            : { on: 'workspace' },
        match: (event) =>
            event.subject_type === 'uploaded_document' &&
            event.subject_id === uploadedDocument.id,
        urlForEvent: () =>
            UploadedDocumentController.show.url({
                tenant: currentWorkspace,
                uploadedDocument: uploadedDocument.id,
            }),
        onData: ({ uploaded_document: next }) => {
            setUploadedDocument(next);
        },
    });

    const downloadUrl = canDownload
        ? UploadedDocumentController.download.url({
              tenant: currentWorkspace,
              uploadedDocument: uploadedDocument.id,
          })
        : null;

    const previewUrl = canDownload
        ? UploadedDocumentController.preview.url({
              tenant: currentWorkspace,
              uploadedDocument: uploadedDocument.id,
          })
        : null;

    const canShowPreview =
        previewUrl !== null &&
        documentPreviewKind(uploadedDocument.original_filename) !==
            'unsupported';

    // Collapsing hands the full width back to the extraction panel, which is
    // otherwise squeezed into half a column.
    const [isPreviewCollapsed, setIsPreviewCollapsed] = useState(false);
    const showPreview = canShowPreview && !isPreviewCollapsed;

    return (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    level={1}
                    title={uploadedDocument.original_filename}
                    description={
                        documentRequest
                            ? t('Extraction for “:title”', {
                                  title: documentRequest.title,
                              })
                            : t('Document extraction')
                    }
                />
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">
                        OCR{' '}
                        {uploadedDocument.processing_status.replaceAll(
                            '_',
                            ' ',
                        )}
                    </Badge>
                    {canShowPreview && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setIsPreviewCollapsed(!isPreviewCollapsed)
                            }
                        >
                            {isPreviewCollapsed
                                ? t('Show preview')
                                : t('Hide preview')}
                        </Button>
                    )}
                    {dossier && (
                        <LinkButton
                            variant="outline"
                            size="sm"
                            href={
                                showDossier({
                                    tenant: currentWorkspace,
                                    dossier: dossier.id,
                                }).url
                            }
                        >
                            {t('Back to dossier')}
                        </LinkButton>
                    )}
                    {canDownload && downloadUrl && (
                        <a
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                            href={downloadUrl}
                        >
                            {t('Download')}
                        </a>
                    )}
                </div>
            </div>

            <p className="font-data text-muted-foreground text-sm">
                {formatBytes(uploadedDocument.size_bytes)} · {t('uploaded')}{' '}
                {formatDateTime(uploadedDocument.uploaded_at)}
            </p>

            {uploadedDocument.processing_status === 'failed' &&
                uploadedDocument.processing_error && (
                    <ErrorState
                        variant="inline"
                        title={t('Processing failed')}
                        description={uploadedDocument.processing_error}
                    />
                )}

            {(uploadedDocument.processing_status === 'pending' ||
                uploadedDocument.processing_status === 'processing') && (
                <p className="text-muted-foreground text-sm">
                    {t(ocrRunningBannerMessage(uploadedDocument))}
                </p>
            )}

            <div
                className={
                    showPreview
                        ? 'grid gap-6 lg:grid-cols-2 lg:items-start'
                        : undefined
                }
            >
                {showPreview && previewUrl ? (
                    <DocumentPreviewPanel
                        filename={uploadedDocument.original_filename}
                        previewUrl={previewUrl}
                    />
                ) : null}

                <DocumentExtractionView
                    extraction={uploadedDocument.extraction}
                    rawJson={uploadedDocument.raw_json}
                />
            </div>
        </div>
    );
}
