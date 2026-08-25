import AuditMark from '@/components/brand/audit-mark';
import { Badge } from '@/components/ui/badge';
import {
    Item,
    ItemContent,
    ItemDescription,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import { getQuestionnaireItemTypeIcon } from '@/features/document-requests/item-types/icons';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * One line of the outstanding-items schedule.
 *
 * The audit mark sits in ItemMedia so it lines up with the brand's paper
 * margin. It is aria-hidden by contract: the group heading above names the
 * status in words for every row underneath.
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
        <Item
            role="listitem"
            variant="default"
            size="sm"
            className="rounded-none border-0 even:bg-ledger-stripe"
        >
            <ItemMedia>
                <AuditMark variant={isRejected ? 'queried' : 'open'} />
            </ItemMedia>

            <ItemContent className="min-w-0">
                <ItemTitle className="min-w-0 text-sm">
                    <Icon
                        className="size-3.5 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                    <span className="min-w-0 truncate">{request.title}</span>
                    {isOptional && !notApplicable ? (
                        <Badge variant="secondary" className="shrink-0">
                            {t('Optional')}
                        </Badge>
                    ) : null}
                </ItemTitle>

                {request.instructions && !notApplicable ? (
                    <ItemDescription className="text-pretty">
                        {request.instructions}
                    </ItemDescription>
                ) : null}

                {notApplicable ? (
                    <ItemDescription className="text-pretty">
                        {request.is_visible
                            ? t(
                                  'Optional and unanswered — does not block progress.',
                              )
                            : t(
                                  'Hidden by a visibility rule. Any past answer is kept for audit.',
                              )}
                    </ItemDescription>
                ) : null}

                {/*
                 * The firm's own note in the margin, and the one place coral
                 * appears in this panel. The reason is mandatory server-side, so
                 * a returned row always has something to say here.
                 */}
                {isRejected && !notApplicable && request.rejection_reason ? (
                    <div
                        className={cn(
                            'mt-1.5 border-l-2 border-brand-border pl-3',
                        )}
                    >
                        <p className="text-xs text-pretty">
                            {request.rejection_reason}
                        </p>
                        {request.reviewed_at ? (
                            <p className="mt-1 font-data text-[0.6875rem] text-muted-foreground">
                                {request.reviewed_by_name
                                    ? t('Returned by :name on :date', {
                                          name: request.reviewed_by_name,
                                          date: formatDate(
                                              request.reviewed_at,
                                          ),
                                      })
                                    : t('Returned on :date', {
                                          date: formatDate(
                                              request.reviewed_at,
                                          ),
                                      })}
                            </p>
                        ) : null}
                    </div>
                ) : null}
            </ItemContent>
        </Item>
    );
}
