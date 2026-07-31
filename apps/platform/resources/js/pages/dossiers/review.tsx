import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import UploadedDocumentController from '@/actions/App/Dossiers/Http/Controllers/UploadedDocumentController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import ReviewItemsPanel from '@/features/document-requests/staff/review/review-items-panel';
import type {
    DocumentRequest,
    UploadedDocument,
} from '@/features/document-requests/types';
import { DossierPermissionsProvider } from '@/features/dossiers/permissions/dossier-permissions-context';
import DossierWorkflowCard from '@/features/dossiers/show/dossier-workflow-card';
import DossierStatusBadge from '@/features/dossiers/status/dossier-status-badge';
import type { Dossier } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFetchOnPipelineUpdated } from '@/hooks/use-fetch-on-pipeline-updated';
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
    dossier: Dossier;
    templates?: unknown[];
    access_grant_token?: string | null;
    access_grant_portal_url?: string | null;
    can_manage: boolean;
    can_edit: boolean;
    can_send_reminder: boolean;
    can_review: boolean;
    can_download: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [documentRequests, setDocumentRequests] = useState(
        dossier.document_requests,
    );

    // Sync when Inertia replaces props (review actions, uploads, navigation).
    useEffect(() => {
        setDocumentRequests(dossier.document_requests);
    }, [dossier.document_requests]);

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

    const hasInFlightProcessing = documentRequests.some((request) => {
        const status = request.uploaded_document?.processing_status;

        return status === 'pending' || status === 'processing';
    });

    useFetchOnPipelineUpdated<{ uploaded_document: UploadedDocument }>({
        enabled: hasInFlightProcessing,
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
                dossierStatus={dossier.status}
                hasFormItems={documentRequests.length > 0}
            >
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="space-y-3">
                            <Button
                                variant="ghost"
                                size="sm"
                                className="px-0"
                                asChild
                            >
                                <Link
                                    href={showDossier({
                                        tenant: currentWorkspace,
                                        dossier: dossier.id,
                                    })}
                                >
                                    <ArrowLeft aria-hidden="true" />
                                    {t('Back to dossier')}
                                </Link>
                            </Button>
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
                                        'Document analysis is still running. Results will update automatically.',
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
        if (request.uploaded_document?.id !== uploadedDocument.id) {
            return request;
        }

        return {
            ...request,
            uploaded_document: uploadedDocument,
        };
    });
}
