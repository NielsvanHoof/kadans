import AuditMark from '@/components/brand/audit-mark';
import { Badge } from '@/components/ui/badge';
import { getQuestionnaireItemTypeIcon } from '@/features/document-requests/item-types/icons';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';

/**
 * One line of the outstanding-items schedule.
 *
 * The row is ruled like working paper: a 2.5rem margin carrying the audit mark,
 * then the item itself. That inset is not arbitrary — it is where
 * `LedgerField` draws the coral margin rule, so the marks land in the margin
 * this brand's paper already has.
 *
 * The mark is `aria-hidden` by contract, so the row never states its status in
 * the margin alone: the group heading above it names the status in words for
 * every row underneath.
 */
export default function WaitingItemRow({
    request,
    notApplicable = false,
}: {
    request: DocumentRequest;
    /** Unanswered but not being asked — explains itself instead of being chased. */
    notApplicable?: boolean;
}) {
    const { t } = useTranslation();
    const { formatDate } = useFormatters();
    const Icon = getQuestionnaireItemTypeIcon(request.type);
    const isRejected = request.status === 'rejected';
    const isOptional = request.configuration?.is_required === false;

    return (
        <li className="grid grid-cols-[2.5rem_minmax(0,1fr)] items-start py-3 pr-4 even:bg-ledger-stripe">
            <span className="flex h-5 items-center justify-center">
                <AuditMark variant={isRejected ? 'queried' : 'open'} />
            </span>

            <div className="min-w-0">
                <div className="flex min-w-0 items-center gap-2">
                    <Icon
                        className="size-3.5 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                    <span className="min-w-0 truncate font-medium">
                        {request.title}
                    </span>
                    {isOptional && !notApplicable ? (
                        <Badge variant="secondary" className="shrink-0">
                            {t('Optional')}
                        </Badge>
                    ) : null}
                </div>

                {/* What the client was actually told to send. */}
                {request.instructions && !notApplicable ? (
                    <p className="mt-1 line-clamp-2 text-xs text-pretty text-muted-foreground">
                        {request.instructions}
                    </p>
                ) : null}

                {notApplicable ? (
                    <p className="mt-1 text-xs text-pretty text-muted-foreground">
                        {request.is_visible
                            ? t(
                                  'Optional and unanswered — does not block progress.',
                              )
                            : t(
                                  'Hidden by a visibility rule. Any past answer is kept for audit.',
                              )}
                    </p>
                ) : null}

                {/*
                 * The firm's own note in the margin, and the one place coral
                 * appears in this panel. The reason is mandatory server-side, so
                 * a returned row always has something to say here — and the
                 * person chasing tomorrow is rarely the person who rejected it.
                 */}
                {isRejected && !notApplicable && request.rejection_reason ? (
                    <div className="mt-2 border-l-2 border-brand-border pl-3">
                        <p className="text-xs text-pretty">
                            {request.rejection_reason}
                        </p>
                        {request.reviewed_at ? (
                            <p className="mt-1 font-data text-[0.6875rem] text-muted-foreground">
                                {request.reviewed_by_name
                                    ? t('Returned by :name on :date', {
                                          name: request.reviewed_by_name,
                                          date: formatDate(request.reviewed_at),
                                      })
                                    : t('Returned on :date', {
                                          date: formatDate(request.reviewed_at),
                                      })}
                            </p>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </li>
    );
}
