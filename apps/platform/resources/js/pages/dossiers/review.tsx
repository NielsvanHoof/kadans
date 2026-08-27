import { Head, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import UploadedDocumentController from '@/actions/App/Dossiers/Http/Controllers/UploadedDocumentController';
import Heading from '@/components/primitives/heading';
import { LinkButton } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { ocrReviewBannerMessage } from '@/features/document-requests/staff/review/ocr-processing-copy';
import ReviewItemsPanel from '@/features/document-requests/staff/review/review-items-panel';
import type {
    DocumentRequest,
    UploadedDocument,
} from '@/features/document-requests/types';
import { DossierPermissionsProvider } from '@/features/dossiers/permissions/dossier-permissions-context';
import DossierWorkflowCard from '@/features/dossiers/show/dossier-workflow-card';
import DossierStatusBadge from '@/features/dossiers/status/dossier-status-badge';
import type { DossierReview } from '@/features/dossiers/types';
import { useFetchOnPipelineUpdated } from '@/features/pipelines/hooks/use-fetch-on-pipeline-updated';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    index as dossierIndex,
    show as showDossier,
} from '@/routes/workspaces/dossiers';

/**
 * Focused review queue for a dossier's questionnaire items.
 * OCR status refreshes via a live JSON island (not a full dossier reload).
 */
export default function ReviewDossier({
    dossier,
    can_manage: canManage,
    can_edit: canEditDetails,
    can_send_reminder: canSendReminder,
    can_review: canReview,
    can_download: canDownload,
}: {
    dossier: DossierReview;
    can_manage: boolean;
    can_edit: boolean;
    can_send_reminder: boolean;
    can_review: boolean;
    can_download: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    // Local list so Echo OCR patches can merge without waiting for Inertia.
    // Adjust during render when props change — avoids a wasted effect pass.
    const [documentRequests, setDocumentRequests] = useState(
        dossier.document_requests,
    );
    const [documentRequestsProp, setDocumentRequestsProp] = useState(
        dossier.document_requests,
    );

    if (dossier.document_requests !== documentRequestsProp) {
        setDocumentRequestsProp(dossier.document_requests);
        setDocumentRequests(dossier.document_requests);
    }

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Dossiers'),
                href: dossierIndex(currentWorkspace),
            },
            {
                title: dossier.title,
                href: showDossier({
                    tenant: currentWorkspace,
                    dossier: dossier.id,
                }),
            },
            {
                title: t('Review'),
                href: '#',
            },
        ],
    });

    const hasInFlightProcessing = documentRequests.some((request) =>
        request.uploaded_documents.some(
            (uploadedDocument) =>
                uploadedDocument.processing_status === 'pending' ||
                uploadedDocument.processing_status === 'processing',
        ),
    );

    const firstInFlightDocument =
        documentRequests
            .flatMap((request) => request.uploaded_documents)
            .find(
                (uploadedDocument) =>
                    uploadedDocument.processing_status === 'pending' ||
                    uploadedDocument.processing_status === 'processing',
            ) ?? null;

    useFetchOnPipelineUpdated<{ uploaded_document: UploadedDocument }>({
        enabled: hasInFlightProcessing,
        scope: { on: 'dossier', dossierId: dossier.id },
        match: (event) =>
            event.dossier_id === dossier.id &&
            event.subject_type === 'uploaded_document',
        urlForEvent: (event) =>
            UploadedDocumentController.live.url({
                tenant: currentWorkspace,
                uploadedDocument: event.subject_id,
            }),
        onData: ({ uploaded_document: uploadedDocument }) => {
            setDocumentRequests((previous) =>
                mergeUploadedDocument(previous, uploadedDocument),
            );
        },
    });

    return (
        <>
            <Head title={`${t('Review')} · ${dossier.title}`} />
            <DossierPermissionsProvider
                canManage={canManage}
                canReview={canReview}
                canDownload={canDownload}
                canEditDetails={canEditDetails}
                canSendReminder={canSendReminder}
                // Audio recordings are not surfaced on the review queue.
                canUploadAudio={false}
                canDownloadAudio={false}
                dossierStatus={dossier.status}
                hasFormItems={documentRequests.length > 0}
            >
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="space-y-3">
                            <LinkButton
                                variant="ghost"
                                size="sm"
                                className="px-0"
                                href={
                                    showDossier({
                                        tenant: currentWorkspace,
                                        dossier: dossier.id,
                                    }).url
                                }
                            >
                                <ArrowLeft aria-hidden="true" />
                                {t('Back to dossier')}
                            </LinkButton>
                            <Heading
                                level={1}
                                className="mb-0"
                                title={t('Review')}
                                description={`${dossier.title} · ${dossier.client.name}`}
                            />
                        </div>
                        <DossierStatusBadge status={dossier.status} />
                    </div>

                    {hasInFlightProcessing && (
                        <div
                            className="flex items-center gap-3 rounded-lg border bg-muted/30 px-4 py-3"
                            aria-live="polite"
                            aria-busy="true"
                        >
                            <Spinner className="size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1 space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        firstInFlightDocument === null
                                            ? 'Document analysis is still running. Results will update automatically.'
                                            : ocrReviewBannerMessage(
                                                  firstInFlightDocument,
                                              ),
                                    )}
                                </p>
                                <Skeleton className="h-3 w-full max-w-md" />
                                <Skeleton className="h-3 w-2/3 max-w-sm" />
                            </div>
                        </div>
                    )}

                    <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                        <ReviewItemsPanel
                            dossierId={dossier.id}
                            documentRequests={documentRequests}
                        />
                        <DossierWorkflowCard dossier={dossier} />
                    </div>
                </div>
            </DossierPermissionsProvider>
        </>
    );
}

/** Patch one uploaded document into the matching review-row without touching others. */
function mergeUploadedDocument(
    requests: DocumentRequest[],
    uploadedDocument: UploadedDocument,
): DocumentRequest[] {
    return requests.map((request) => {
        if (
            !request.uploaded_documents.some(
                (existing) => existing.id === uploadedDocument.id,
            )
        ) {
            return request;
        }

        return {
            ...request,
            uploaded_documents: request.uploaded_documents.map((existing) =>
                existing.id === uploadedDocument.id
                    ? uploadedDocument
                    : existing,
            ),
        };
    });
}
