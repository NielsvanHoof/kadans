import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/components/primitives/empty-state';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import {
    notCurrentlyAskedDocumentRequests,
    waitingDocumentRequests,
} from '@/features/dossiers/show/dossier-panel';
import WaitingChaseHeader from '@/features/dossiers/show/waiting-chase-header';
import WaitingItemRow from '@/features/dossiers/show/waiting-item-row';
import type { Dossier } from '@/features/dossiers/types';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * The outstanding-items schedule: what the client is still holding.
 *
 * Set as working paper rather than a list of cards — hairline rules, a margin
 * carrying each item's audit mark, and figures in the data face. The page is a
 * ledger sheet, so a box around a schedule that already rules itself would make
 * a box of boxes.
 *
 * Returned items lead. They are work the firm itself created, they are the most
 * likely to have gone stale, and the reason we sent them back is what staff
 * need in hand when the client rings.
 *
 * Status lives on the group heading, not on every row: once the rows are marked
 * in the margin and gathered under a heading that names their state, repeating
 * that state per row is noise — and the heading is what a screen reader
 * announces for the rows beneath it.
 */
export default function WaitingItemsSummary({
    dossier,
    onSelectAccess,
}: {
    dossier: Dossier;
    onSelectAccess: () => void;
}) {
    const { t } = useTranslation();
    const { dossierStatus } = useDossierCapabilities();

    const waiting = waitingDocumentRequests(dossier.document_requests);
    const notAsked = notCurrentlyAskedDocumentRequests(
        dossier.document_requests,
    );
    const returned = waiting.filter((request) => request.status === 'rejected');
    const pending = waiting.filter((request) => request.status === 'pending');

    // This panel is what the page opens on, so its empty state has to point
    // somewhere real — and a closed dossier has no Review left to switch to.
    if (waiting.length === 0) {
        return (
            <div className="space-y-4">
                <EmptyState
                    title={
                        dossierStatus === 'completed'
                            ? t(
                                  'Every item was approved and the dossier is closed.',
                              )
                            : t(
                                  'Nothing waiting — switch to Review when uploads arrive.',
                              )
                    }
                />
                {notAsked.length > 0 ? (
                    <div className="-mx-4 border-y border-ledger-rule md:mx-0">
                        <NotCurrentlyAskedGroup items={notAsked} />
                    </div>
                ) : null}
            </div>
        );
    }

    return (
        <div className="-mx-4 border-y border-ledger-rule text-sm md:mx-0">
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
 * A schedule under its own heading, with the count set on the rule in mono.
 * The heading sits in the same grid as the rows, so both start at the margin
 * and only the audit marks hang to the left of it.
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
        <section aria-labelledby={id} className="border-t border-ledger-rule">
            <div className="grid grid-cols-[2.5rem_minmax(0,1fr)] items-center py-2 pr-4">
                <span />
                <div className="flex items-baseline justify-between gap-2">
                    <h3
                        id={id}
                        className="truncate font-data text-[0.6875rem] tracking-[0.08em] text-muted-foreground uppercase"
                    >
                        {title}
                    </h3>
                    <span className="font-data text-xs text-muted-foreground tabular-nums">
                        {items.length}
                    </span>
                </div>
            </div>

            <ul className="border-t border-ledger-rule">
                {items.map((request) => (
                    <WaitingItemRow key={request.id} request={request} />
                ))}
            </ul>
        </section>
    );
}

/**
 * Unanswered fields the client is not being asked for, folded away.
 *
 * They are excluded from every count on the page, which is exactly why they
 * need somewhere to be: "the form has nine fields and the panel lists four" is
 * a question staff will ask, and the answer has to be inspectable rather than
 * implied by an absence.
 */
function NotCurrentlyAskedGroup({ items }: { items: DocumentRequest[] }) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);

    return (
        <Collapsible
            isExpanded={expanded}
            onExpandedChange={setExpanded}
            className="border-t border-ledger-rule"
        >
            <CollapsibleTrigger
                type="button"
                variant="ghost"
                className="h-auto w-full justify-start gap-2 rounded-none px-4 py-2.5 font-normal text-muted-foreground"
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
                <ul className="border-t border-ledger-rule">
                    {items.map((request) => (
                        <WaitingItemRow
                            key={request.id}
                            request={request}
                            notApplicable
                        />
                    ))}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}
