import { Download, X } from 'lucide-react';
import ClientPortalUploadController from '@/actions/App/ClientPortal/Http/Controllers/ClientPortalUploadController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    PortalBooleanDocumentAnswer,
    PortalChoiceDocumentAnswer,
    PortalMultiSelectDocumentAnswer,
    PortalTextDocumentAnswer,
} from '@/features/document-requests/portal/portal-document-answer';
import PortalDocumentUpload from '@/features/document-requests/portal/portal-document-upload';
import type {
    PortalDocumentRequest,
    PortalUploadedDocument,
} from '@/features/document-requests/types';
import { storesAnswerText } from '@/features/document-requests/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/format-bytes';
import { cn } from '@/lib/utils';

export type PortalDocumentRequestTypeProps = {
    documentRequest: PortalDocumentRequest;
    canRespond: boolean;
};

/**
 * File requests show the current upload before offering a replacement.
 */
export function PortalFileRequestContent({
    documentRequest,
    canRespond,
}: PortalDocumentRequestTypeProps) {
    const { t } = useTranslation();
    const allowMultiple =
        documentRequest.configuration?.allow_multiple ?? false;
    const canRemove = canRespond && allowMultiple;

    return (
        <div className="space-y-3">
            {documentRequest.uploaded_documents.length > 0 ? (
                <div className="space-y-2">
                    {documentRequest.uploaded_documents.map((uploaded) => (
                        <PortalUploadedFile
                            key={uploaded.id}
                            documentRequest={documentRequest}
                            uploaded={uploaded}
                            canRemove={canRemove}
                        />
                    ))}
                    {!canRespond ? (
                        <p className="text-xs text-muted-foreground">
                            {t('File received.')}
                        </p>
                    ) : null}
                </div>
            ) : null}
            {canRespond ? (
                <PortalDocumentUpload documentRequest={documentRequest} />
            ) : null}
        </div>
    );
}

function PortalUploadedFile({
    documentRequest,
    uploaded,
    canRemove,
}: {
    documentRequest: PortalDocumentRequest;
    uploaded: PortalUploadedDocument;
    canRemove: boolean;
}) {
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();

    return (
        <div className="flex items-center justify-between gap-2 rounded-lg border border-dashed border-border/70 bg-muted/30 px-3 py-2.5 text-sm">
            <div className="min-w-0">
                <p className="truncate font-medium">
                    {uploaded.original_filename}
                </p>
                <p className="text-xs text-muted-foreground">
                    {formatBytes(uploaded.size_bytes)}
                    {uploaded.uploaded_at ? (
                        <> · {formatDateTime(uploaded.uploaded_at)}</>
                    ) : null}
                </p>
            </div>
            <div className="flex shrink-0 items-center gap-1">
                {/*
                    Clients could previously see only a filename, so there was no
                    way to check that the right page of a bank statement went up.
                    It is also their own data, which is an access right.

                    Plain <a>, not LinkButton: app.tsx wires React Aria's
                    navigate to router.visit, so LinkButton would XHR this URL,
                    follow the signed S3 redirect, and fail the CORS preflight
                    (bucket CORS only allows PUT for uploads). Staff downloads
                    use the same plain-anchor pattern for that reason.
                */}
                <a
                    className={cn(
                        buttonVariants({ variant: 'ghost', size: 'icon-xs' }),
                    )}
                    href={ClientPortalUploadController.download.url({
                        documentRequest: documentRequest.id,
                        uploadedDocument: uploaded.id,
                    })}
                    aria-label={t('Download :filename', {
                        filename: uploaded.original_filename,
                    })}
                >
                    <Download aria-hidden />
                </a>
                {canRemove ? (
                    <ConfirmDestroyDialog
                        title={t('Remove :filename?', {
                            filename: uploaded.original_filename,
                        })}
                        description={t(
                            'This file will be permanently removed from your submission.',
                        )}
                        confirmLabel={t('Remove file')}
                        trigger={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                aria-label={t('Remove :filename', {
                                    filename: uploaded.original_filename,
                                })}
                            >
                                <X aria-hidden />
                            </Button>
                        }
                        form={ClientPortalUploadController.destroy.form({
                            documentRequest: documentRequest.id,
                            uploadedDocument: uploaded.id,
                        })}
                    />
                ) : null}
            </div>
        </div>
    );
}

export function PortalTextRequestContent({
    documentRequest,
    canRespond,
}: PortalDocumentRequestTypeProps) {
    if (canRespond) {
        return <PortalTextDocumentAnswer documentRequest={documentRequest} />;
    }

    return <PortalSubmittedAnswerPreview documentRequest={documentRequest} />;
}

export function PortalBooleanRequestContent({
    documentRequest,
    canRespond,
}: PortalDocumentRequestTypeProps) {
    if (canRespond) {
        return (
            <PortalBooleanDocumentAnswer documentRequest={documentRequest} />
        );
    }

    return <PortalSubmittedAnswerPreview documentRequest={documentRequest} />;
}

export function PortalChoiceRequestContent({
    documentRequest,
    canRespond,
}: PortalDocumentRequestTypeProps) {
    if (canRespond) {
        return <PortalChoiceDocumentAnswer documentRequest={documentRequest} />;
    }

    return <PortalSubmittedAnswerPreview documentRequest={documentRequest} />;
}

export function PortalMultiSelectRequestContent({
    documentRequest,
    canRespond,
}: PortalDocumentRequestTypeProps) {
    if (canRespond) {
        return (
            <PortalMultiSelectDocumentAnswer
                documentRequest={documentRequest}
            />
        );
    }

    return <PortalSubmittedAnswerPreview documentRequest={documentRequest} />;
}

/** Read-only answer when the client can no longer edit this item. */
function PortalSubmittedAnswerPreview({
    documentRequest,
}: {
    documentRequest: PortalDocumentRequest;
}) {
    const { t } = useTranslation();

    if (storesAnswerText(documentRequest.type)) {
        if (!documentRequest.answer_text) {
            return null;
        }

        return (
            <div className="rounded-lg bg-muted/50 px-3 py-2.5 text-sm">
                <p className="text-xs text-muted-foreground">
                    {t('Your answer')}
                </p>
                <p className="mt-0.5 font-medium whitespace-pre-wrap">
                    {documentRequest.answer_text}
                </p>
            </div>
        );
    }

    if (
        documentRequest.type === 'boolean' &&
        documentRequest.answer_boolean !== null
    ) {
        return (
            <div className="rounded-lg bg-muted/50 px-3 py-2.5 text-sm">
                <p className="text-xs text-muted-foreground">
                    {t('Your answer')}
                </p>
                <p className="mt-0.5 font-medium">
                    {documentRequest.answer_boolean ? t('Yes') : t('No')}
                </p>
            </div>
        );
    }

    if (
        (documentRequest.type === 'choice' ||
            documentRequest.type === 'multi_select') &&
        documentRequest.answer_option_ids?.length
    ) {
        const labels = (documentRequest.configuration?.options ?? [])
            .filter((option) =>
                documentRequest.answer_option_ids?.includes(option.id),
            )
            .map((option) => option.label);

        if (labels.length === 0) {
            return null;
        }

        return (
            <div className="rounded-lg bg-muted/50 px-3 py-2.5 text-sm">
                <p className="text-xs text-muted-foreground">
                    {t('Your answer')}
                </p>
                <p className="mt-0.5 font-medium whitespace-pre-wrap">
                    {labels.join(', ')}
                </p>
            </div>
        );
    }

    return null;
}
