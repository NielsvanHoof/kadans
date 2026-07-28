import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import DocumentRequestReviewController from '@/actions/App/Dossiers/Http/Controllers/DocumentRequestReviewController';
import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import DocumentRequestReview from '@/features/document-requests/staff/review/document-request-review';
import { getQuestionnaireItemTypeStaffContent } from '@/features/document-requests/staff/review/questionnaire-item-type-renderers';
import { documentRequestStatusLabel } from '@/features/document-requests/status/document-request-status';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

/**
 * Review-stage list: responses, OCR, staff file uploads, and review actions.
 * Field definitions remain read-only here.
 */
export default function ReviewItemsPanel({
    dossierId,
    documentRequests,
}: {
    dossierId: number;
    documentRequests: DocumentRequest[];
}) {
    const { t } = useTranslation();

    const ready = documentRequests.filter(
        (request) => request.status === 'submitted',
    );
    const waiting = documentRequests.filter(
        (request) =>
            request.status === 'pending' || request.status === 'rejected',
    );
    const approved = documentRequests.filter(
        (request) => request.status === 'accepted',
    );

    return (
        <section
            className="space-y-5 rounded-2xl border border-border/70 bg-card p-4 md:p-5"
            aria-labelledby="review-queue-heading"
        >
            <div>
                <h3
                    id="review-queue-heading"
                    className="text-base font-semibold tracking-tight"
                >
                    {t('Review queue')}
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    {t('Approve items or request changes, then complete.')}
                </p>
            </div>

            {documentRequests.length === 0 ? (
                <EmptyState
                    variant="panel"
                    title={t('No questionnaire items on this dossier.')}
                />
            ) : (
                <>
                    <ReviewGroup
                        title={t('Ready to review (:count)', {
                            count: ready.length,
                        })}
                        emptyLabel={t('No items ready to review.')}
                        items={ready}
                        dossierId={dossierId}
                        bulkApproveEnabled
                    />
                    <ReviewGroup
                        title={t('Waiting on client (:count)', {
                            count: waiting.length,
                        })}
                        emptyLabel={t('Nothing waiting on the client.')}
                        items={waiting}
                        dossierId={dossierId}
                    />
                    <ReviewGroup
                        title={t('Approved (:count)', {
                            count: approved.length,
                        })}
                        emptyLabel={t('No approved items yet.')}
                        items={approved}
                        dossierId={dossierId}
                    />
                </>
            )}
        </section>
    );
}

function ReviewGroup({
    title,
    emptyLabel,
    items,
    dossierId,
    bulkApproveEnabled = false,
}: {
    title: string;
    emptyLabel: string;
    items: DocumentRequest[];
    dossierId: number;
    bulkApproveEnabled?: boolean;
}) {
    const { t } = useTranslation();
    const { canReview } = useDossierCapabilities();
    const currentWorkspace = useCurrentWorkspace();
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [bulkProcessing, setBulkProcessing] = useState(false);
    const [focusedId, setFocusedId] = useState<number | null>(null);

    const showBulk = bulkApproveEnabled && canReview && items.length > 0;

    const approveOne = useCallback(
        (documentRequestId: number): Promise<void> => {
            const url = DocumentRequestReviewController.store.url({
                tenant: currentWorkspace,
                dossier: dossierId,
                documentRequest: documentRequestId,
            });

            return new Promise((resolve, reject) => {
                router.post(
                    url,
                    { decision: 'accepted' },
                    {
                        ...inlineActionOptions,
                        preserveScroll: true,
                        onSuccess: () => resolve(),
                        onError: () => reject(new Error('Approve failed')),
                    },
                );
            });
        },
        [currentWorkspace, dossierId],
    );

    const approveSelected = useCallback(async () => {
        if (selectedIds.size === 0) {
            return;
        }

        setBulkProcessing(true);

        try {
            for (const id of selectedIds) {
                await approveOne(id);
            }
            setSelectedIds(new Set());
        } finally {
            setBulkProcessing(false);
        }
    }, [approveOne, selectedIds]);

    useEffect(() => {
        if (!showBulk) {
            return;
        }

        function handleKeyDown(event: KeyboardEvent): void {
            const target = event.target as HTMLElement | null;
            if (
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.isContentEditable)
            ) {
                return;
            }

            if (
                event.key !== 'a' ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey
            ) {
                return;
            }

            const readyItem =
                items.find((item) => item.id === focusedId) ?? items[0];

            if (!readyItem) {
                return;
            }

            event.preventDefault();
            void approveOne(readyItem.id);
        }

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [approveOne, focusedId, items, showBulk]);

    const allSelected =
        items.length > 0 && items.every((item) => selectedIds.has(item.id));

    return (
        <section className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="text-sm font-medium">{title}</h4>
                {showBulk && selectedIds.size > 0 ? (
                    <Button
                        type="button"
                        size="sm"
                        disabled={bulkProcessing}
                        onClick={() => void approveSelected()}
                    >
                        <Check aria-hidden />
                        {t('Approve selected (:count)', {
                            count: selectedIds.size,
                        })}
                    </Button>
                ) : null}
            </div>

            {items.length === 0 ? (
                <EmptyState variant="compact" title={emptyLabel} />
            ) : (
                <ul className="divide-y overflow-hidden rounded-xl border border-border/70">
                    {items.map((documentRequest) => {
                        const definition = getQuestionnaireItemTypeDefinition(
                            documentRequest.type,
                        );
                        const StaffContent =
                            getQuestionnaireItemTypeStaffContent(
                                documentRequest.type,
                            );
                        const selectable =
                            showBulk && documentRequest.status === 'submitted';

                        return (
                            <li
                                key={documentRequest.id}
                                className="space-y-3 px-4 py-3"
                                tabIndex={showBulk ? 0 : undefined}
                                onFocus={() => setFocusedId(documentRequest.id)}
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    {selectable ? (
                                        <Checkbox
                                            checked={selectedIds.has(
                                                documentRequest.id,
                                            )}
                                            onCheckedChange={(checked) => {
                                                setSelectedIds((current) => {
                                                    const next = new Set(
                                                        current,
                                                    );
                                                    if (checked) {
                                                        next.add(
                                                            documentRequest.id,
                                                        );
                                                    } else {
                                                        next.delete(
                                                            documentRequest.id,
                                                        );
                                                    }
                                                    return next;
                                                });
                                            }}
                                            aria-label={t(
                                                'Select for bulk approve',
                                            )}
                                        />
                                    ) : null}
                                    <p className="min-w-0 truncate text-sm font-medium">
                                        {documentRequest.title}
                                    </p>
                                    <Badge variant="outline">
                                        {t(definition.label)}
                                    </Badge>
                                    <Badge variant="secondary">
                                        {documentRequestStatusLabel(
                                            documentRequest.status,
                                            t,
                                        )}
                                    </Badge>
                                </div>
                                <StaffContent
                                    dossierId={dossierId}
                                    documentRequest={documentRequest}
                                />
                                <DocumentRequestReview
                                    dossierId={dossierId}
                                    documentRequest={documentRequest}
                                />
                            </li>
                        );
                    })}
                </ul>
            )}

            {showBulk && items.length > 0 ? (
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Checkbox
                        checked={
                            allSelected
                                ? true
                                : selectedIds.size > 0
                                  ? 'indeterminate'
                                  : false
                        }
                        onCheckedChange={(checked) => {
                            if (checked) {
                                setSelectedIds(
                                    new Set(items.map((item) => item.id)),
                                );
                            } else {
                                setSelectedIds(new Set());
                            }
                        }}
                        aria-label={t('Select all ready items')}
                    />
                    <span>
                        {t('Press :key to approve the focused item', {
                            key: 'a',
                        })}
                    </span>
                </div>
            ) : null}
        </section>
    );
}
