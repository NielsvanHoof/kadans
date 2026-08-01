import EmptyState from '@/components/primitives/empty-state';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import { getQuestionnaireItemTypePortalContent } from '@/features/document-requests/item-types/portal-registry';
import { documentRequestStatusLabel } from '@/features/document-requests/status/document-request-status';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Client-portal questionnaire — numbered field cards aligned with the staff builder canvas.
 */
export default function PortalDocumentRequestsCard({
    firmName,
    documentRequests,
    nextIncompleteRequestId,
}: {
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
                    <p className="font-data text-sm text-muted-foreground tabular-nums">
                        {t(':count fields', { count: documentRequests.length })}
                    </p>
                </div>
                <p className="text-base text-pretty text-muted-foreground">
                    {t(
                        'Complete each item. Submitted items stay locked while they are reviewed.',
                    )}
                </p>
            </div>

            {documentRequests.length === 0 ? (
                <EmptyState
                    variant="panel"
                    title={t(
                        'Nothing has been requested yet. Check back later or contact :firm.',
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
                                    'scroll-mt-6 list-none rounded-2xl border bg-card shadow-xs transition-colors duration-150 motion-reduce:transition-none',
                                    isNext
                                        ? 'border-primary ring-[3px] ring-ring/40'
                                        : 'border-border/70',
                                )}
                            >
                                <div className="flex items-start gap-3 px-4 py-3.5 md:px-5">
                                    <span className="mt-1 w-6 shrink-0 font-data text-sm text-muted-foreground tabular-nums">
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
                                            <StatusBadge
                                                status={documentRequest.status}
                                            />
                                        </div>
                                        {documentRequest.instructions ? (
                                            <p className="text-base text-pretty text-muted-foreground">
                                                {documentRequest.instructions}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>

                                <div className="space-y-4 border-t border-border/60 px-4 py-4 md:px-5">
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

function StatusBadge({ status }: { status: PortalDocumentRequest['status'] }) {
    const { t } = useTranslation();

    return (
        <Badge
            variant="outline"
            className={cn(
                'shrink-0',
                status === 'accepted' &&
                    'border-success-border bg-success-muted text-success-foreground',
                status === 'rejected' &&
                    'border-destructive/30 bg-destructive/5 text-destructive',
                status === 'submitted' && 'bg-muted/60',
            )}
        >
            {documentRequestStatusLabel(status, t)}
        </Badge>
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
            <p className="text-sm text-muted-foreground">
                {t('Submitted and waiting for review.')}
            </p>
        );
    }

    if (documentRequest.status === 'accepted') {
        return (
            <p className="text-sm text-success-foreground">
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
    documentRequest,
    canRespond,
}: {
    documentRequest: PortalDocumentRequest;
    canRespond: boolean;
}) {
    const PortalContent = getQuestionnaireItemTypePortalContent(
        documentRequest.type,
    );

    return (
        <PortalContent
            documentRequest={documentRequest}
            canRespond={canRespond}
        />
    );
}
