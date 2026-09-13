import { Trash2 } from 'lucide-react';
import UploadedDocumentController from '@/actions/App/Dossiers/Http/Controllers/UploadedDocuments/UploadedDocumentController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import ErrorState from '@/components/primitives/error-state';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants, LinkButton } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ocrProcessingMessage } from '@/features/document-requests/staff/review/ocr-processing-copy';
import { documentProcessingStatusLabel } from '@/features/document-requests/status/document-processing-status';
import type {
    DocumentProcessingStatus,
    UploadedDocument,
} from '@/features/document-requests/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/format-bytes';

export default function DocumentProcessingPanel({
    uploadedDocument,
    canDownload,
    canRemove = false,
}: {
    uploadedDocument: UploadedDocument;
    canDownload: boolean;
    /** Only offered on multi-file requests, where removing one file leaves the rest intact. */
    canRemove?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();
    const isComplete = uploadedDocument.processing_status === 'completed';

    return (
        <div className="bg-muted/50 space-y-3 rounded-md px-3 py-2 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="truncate font-medium">
                            {uploadedDocument.original_filename}
                        </p>
                        <Badge
                            variant={processingBadgeVariant(
                                uploadedDocument.processing_status,
                            )}
                        >
                            {t('Scan :status', {
                                status: documentProcessingStatusLabel(
                                    uploadedDocument.processing_status,
                                    t,
                                ),
                            })}
                        </Badge>
                    </div>
                    <p className="text-muted-foreground">
                        {formatBytes(uploadedDocument.size_bytes)} ·{' '}
                        {formatDateTime(uploadedDocument.uploaded_at)}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {isComplete && (
                        <LinkButton
                            variant="secondary"
                            size="sm"
                            href={UploadedDocumentController.show.url({
                                tenant: currentWorkspace,
                                uploadedDocument: uploadedDocument.id,
                            })}
                        >
                            {t('View extraction')}
                        </LinkButton>
                    )}
                    {canDownload && (
                        <a
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                            href={UploadedDocumentController.download.url({
                                tenant: currentWorkspace,
                                uploadedDocument: uploadedDocument.id,
                            })}
                        >
                            {t('Download')}
                        </a>
                    )}
                    {canRemove && (
                        <ConfirmDestroyDialog
                            title={t('Remove :filename?', {
                                filename: uploadedDocument.original_filename,
                            })}
                            description={t(
                                'This file will be permanently removed from the request.',
                            )}
                            confirmLabel={t('Remove file')}
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    aria-label={t('Remove :filename', {
                                        filename:
                                            uploadedDocument.original_filename,
                                    })}
                                >
                                    <Trash2 aria-hidden />
                                </Button>
                            }
                            form={UploadedDocumentController.destroy.form({
                                tenant: currentWorkspace,
                                uploadedDocument: uploadedDocument.id,
                            })}
                        />
                    )}
                </div>
            </div>

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
                <div className="space-y-2" aria-live="polite">
                    <p className="text-muted-foreground">
                        {t(ocrProcessingMessage(uploadedDocument))}
                    </p>
                    <Skeleton className="h-4 w-full max-w-md" />
                    <Skeleton className="h-4 w-2/3" />
                </div>
            )}
        </div>
    );
}

function processingBadgeVariant(
    status: DocumentProcessingStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed':
            return 'default';
        case 'failed':
            return 'destructive';
        case 'processing':
            return 'secondary';
        default:
            return 'outline';
    }
}
