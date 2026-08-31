import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/components/primitives/empty-state';
import { LinkButton } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ItemGroup } from '@/components/ui/item';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import {
    notCurrentlyAskedDocumentRequests,
    waitingDocumentRequests,
} from '@/features/dossiers/show/dossier-panel';
import WaitingChaseHeader from '@/features/dossiers/show/waiting-chase-header';
import WaitingItemRow from '@/features/dossiers/show/waiting-item-row';
import type { Dossier } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { review as reviewDossier } from '@/routes/workspaces/dossiers';

/**
 * The outstanding-items schedule: what the client is still holding.
 *
 * Returned items lead. They are work the firm itself created, they are the
 * most likely to have gone stale, and the reason we sent them back is what
 * staff need in hand when the client rings.
 *
 * Status lives on the group heading, not on every row.
 */
export default function WaitingItemsSummary({
    dossier,
    onSelectAccess,
}: {
    dossier: Dossier;
    onSelectAccess: () => void;
}) {
    const { t } = useTranslation();
    const { dossierStatus, canReview } = useDossierCapabilities();
    const currentWorkspace = useCurrentWorkspace();

    const waiting = waitingDocumentRequests(dossier.document_requests);
    const notAsked = notCurrentlyAskedDocumentRequests(
        dossier.document_requests,
    );
    const returned = waiting.filter((request) => request.status === 'rejected');
    const pending = waiting.filter((request) => request.status === 'pending');

    if (waiting.length === 0) {
        const isClosed = dossierStatus === 'completed';

        return (
            <div className="space-y-4">
                <EmptyState
                    title={
                        isClosed
                            ? t(
                                  'Every item was approved and the dossier is closed.',
                              )
                            : t(
                                  'Nothing waiting — switch to Review when uploads arrive.',
                              )
                    }
                    action={
                        canReview && !isClosed ? (
                            <LinkButton
                                variant="outline"
                                href={
                                    reviewDossier({
                                        tenant: currentWorkspace,
                                        dossier: dossier.id,
                                    }).url
                                }
                            >
                                {t('Go to Review')}
                            </LinkButton>
                        ) : undefined
                    }
                />
                {notAsked.length > 0 ? (
                    <NotCurrentlyAskedGroup items={notAsked} />
                ) : null}
            </div>
        );
    }

    return (
        <div className="space-y-4 text-sm">
            <WaitingChaseHeader
                dossier={dossier}
                openCount={waiting.length}
                onSelectAccess={onSelectAccess}
            />

            <WaitingGroup
                id="waiting-returned"
                title={t('Returned for correction')}
                items={returned}
            />

            <WaitingGroup
                id="waiting-pending"
                title={t('Waiting on client')}
                items={pending}
            />

            {notAsked.length > 0 ? (
                <NotCurrentlyAskedGroup items={notAsked} />
            ) : null}
        </div>
    );
}

/**
 * A schedule under its own heading, with the count set in mono.
 */
function WaitingGroup({
    id,
    title,
    items,
}: {
    id: string;
    title: string;
    items: DocumentRequest[];
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby={id} className="min-w-0">
            <div className="flex items-baseline justify-between gap-2 px-1 pb-2">
                <h3
                    id={id}
                    className="font-data text-muted-foreground scroll-mt-20 truncate text-[0.6875rem] tracking-[0.08em] uppercase"
                >
                    {title}
                </h3>
                <span className="font-data text-muted-foreground text-xs tabular-nums">
                    {items.length}
                </span>
            </div>

            <ItemGroup className="ring-foreground/10 gap-0 overflow-hidden rounded-md ring-1">
                {items.map((request) => (
                    <WaitingItemRow key={request.id} request={request} />
                ))}
            </ItemGroup>
        </section>
    );
}

/**
 * Unanswered fields the client is not being asked for, folded away.
 *
 * They are excluded from every count on the page, which is exactly why they
 * need somewhere to be: "the form has nine fields and the panel lists four"
 * is a question staff will ask.
 */
function NotCurrentlyAskedGroup({ items }: { items: DocumentRequest[] }) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);

    return (
        <Collapsible
            isExpanded={expanded}
            onExpandedChange={setExpanded}
            className="ring-foreground/10 overflow-hidden rounded-md ring-1"
        >
            <CollapsibleTrigger
                type="button"
                variant="ghost"
                className="text-muted-foreground h-auto w-full justify-start gap-2 rounded-none px-3 py-2.5 font-normal"
            >
                <ChevronRight
                    className={cn(
                        'size-3.5 shrink-0 transition-transform duration-150 motion-reduce:transition-none',
                        expanded && 'rotate-90',
                    )}
                    aria-hidden
                />
                {t('Not currently asked (:count)', { count: items.length })}
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ItemGroup className="gap-0 border-t">
                    {items.map((request) => (
                        <WaitingItemRow
                            key={request.id}
                            request={request}
                            notApplicable
                        />
                    ))}
                </ItemGroup>
            </CollapsibleContent>
        </Collapsible>
    );
}
