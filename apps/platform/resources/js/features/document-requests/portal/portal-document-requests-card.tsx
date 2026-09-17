import EmptyState from '@/components/primitives/empty-state';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import { getQuestionnaireItemTypePortalContent } from '@/features/document-requests/item-types/portal-registry';
import { PortalCarriedForwardAnswer } from '@/features/document-requests/portal/portal-carried-forward-answer';
import DocumentRequestStatusBadge from '@/features/document-requests/status/document-request-status-badge';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Client-portal questionnaire — numbered field cards aligned with the staff builder canvas.
 */
export default function PortalDocumentRequestsCard({
    dossierId,
    firmName,
    documentRequests,
    nextIncompleteRequestId,
}: {
    dossierId: number;
    firmName: string;
    documentRequests: PortalDocumentRequest[];
    nextIncompleteRequestId: number | null;
}) {
    const { t } = useTranslation();

    return (
        <section className="space-y-4" aria-label={t('Questionnaire')}>
            <div className="space-y-2">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="font-display text-xl font-semibold tracking-tight">
                        {t('Your form')}
                    </h2>
                    <p className="font-data text-muted-foreground text-sm tabular-nums">
                        {t(':count fields', { count: documentRequests.length })}
                    </p>
                </div>
                <p className="text-muted-foreground text-base text-pretty">
                    {t(
                        'Complete each item. Submitted items stay locked while they are reviewed.',
                    )}
                </p>
            </div>

            {documentRequests.length === 0 ? (
                <EmptyState
                    variant="panel"
                    title={t(
                        'Nothing has been requested yet. :firm will let you know when something is needed.',
                        { firm: firmName },
                    )}
                />
            ) : (
                <ol className="space-y-4">
                    {documentRequests.map((documentRequest, index) => {
                        const isNext =
                            documentRequest.id === nextIncompleteRequestId;
                        const definition = getQuestionnaireItemTypeDefinition(
                            documentRequest.type,
                        );

                        return (
                            <li
                                key={documentRequest.id}
                                id={`request-${documentRequest.id}`}
                                className={cn(
                                    'bg-card scroll-mt-6 list-none rounded-2xl border shadow-xs transition-colors duration-150 motion-reduce:transition-none',
                                    isNext
                                        ? 'border-primary ring-ring/40 ring-[3px]'
                                        : 'border-border/70',
                                )}
                            >
                                <div className="flex items-start gap-3 px-4 py-3.5 md:px-5">
                                    <span className="font-data text-muted-foreground mt-1 w-6 shrink-0 text-sm tabular-nums">
                                        {index + 1}
                                    </span>
                                    <div className="min-w-0 flex-1 space-y-1.5">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="min-w-0 flex-1 text-lg font-medium text-pretty">
                                                {documentRequest.title}
                                            </h3>
                                            <Badge
                                                variant="outline"
                                                className="shrink-0"
                                            >
                                                {t(definition.label)}
                                            </Badge>
                                            <DocumentRequestStatusBadge
                                                status={documentRequest.status}
                                            />
                                        </div>
                                        {documentRequest.instructions ? (
                                            <p className="text-muted-foreground text-base text-pretty">
                                                {documentRequest.instructions}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>

                                <div className="border-border/60 space-y-4 border-t px-4 py-4 md:px-5">
                                    {documentRequest.status === 'rejected' &&
                                    documentRequest.rejection_reason ? (
                                        <Alert variant="destructive">
                                            <AlertTitle>
                                                {t('Changes requested')}
                                            </AlertTitle>
                                            <AlertDescription>
                                                {
                                                    documentRequest.rejection_reason
                                                }
                                            </AlertDescription>
                                        </Alert>
                                    ) : null}

                                    <PortalRequestTypeContent
                                        dossierId={dossierId}
                                        documentRequest={documentRequest}
                                        canRespond={documentRequest.can_respond}
                                    />

                                    <FieldFooter
                                        firmName={firmName}
                                        documentRequest={documentRequest}
                                    />
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}

function FieldFooter({
    firmName,
    documentRequest,
}: {
    firmName: string;
    documentRequest: PortalDocumentRequest;
}) {
    const { t } = useTranslation();

    if (documentRequest.can_respond) {
        return null;
    }

    if (documentRequest.status === 'submitted') {
        return (
            <p className="text-muted-foreground text-sm">
                {t('Submitted and waiting for review.')}
            </p>
        );
    }

    if (documentRequest.status === 'accepted') {
        return (
            <p className="text-success-foreground text-sm">
                {t('Approved by :firm.', { firm: firmName })}
            </p>
        );
    }

    return null;
}

/**
 * Resolve type-specific portal content through the exhaustive registry.
 */
function PortalRequestTypeContent({
    dossierId,
    documentRequest,
    canRespond,
}: {
    dossierId: number;
    documentRequest: PortalDocumentRequest;
    canRespond: boolean;
}) {
    const PortalContent = getQuestionnaireItemTypePortalContent(
        documentRequest.type,
    );

    const content = (
        <PortalContent
            dossierId={dossierId}
            documentRequest={documentRequest}
            canRespond={canRespond}
        />
    );

    if (!canRespond || !documentRequest.is_carried_forward) {
        return content;
    }

    return (
        <PortalCarriedForwardAnswer documentRequest={documentRequest}>
            {content}
        </PortalCarriedForwardAnswer>
    );
}
