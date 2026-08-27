import { Link } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import {
    Pagination,
    PaginationContent,
    PaginationItem,
} from '@/components/ui/pagination';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { SimplePaginated } from '@/types/pagination';

/**
 * Prev/next links for a Laravel simple paginator — no page numbers, no total.
 *
 * Used by the activity log so each view skips COUNT(*) on activity_log.
 * Other indexes keep IndexPagination / LengthAwarePaginator.
 */
export default function SimpleIndexPagination<T>({
    paginator,
}: {
    paginator: SimplePaginated<T>;
}) {
    const { t } = useTranslation();

    if (paginator.data.length === 0) {
        return null;
    }

    const prevUrl = paginator.prev_page_url;
    const nextUrl = paginator.next_page_url;
    const onFirst = paginator.current_page <= 1 || prevUrl === null;
    const onLast = nextUrl === null;
    const showLinks = !onFirst || !onLast;

    const rangeText =
        paginator.from !== null && paginator.to !== null
            ? t('Showing :from–:to', {
                  from: paginator.from,
                  to: paginator.to,
              })
            : null;

    return (
        <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            {rangeText ? (
                <p className="text-sm text-muted-foreground">{rangeText}</p>
            ) : null}

            {showLinks ? (
                <Pagination>
                    <PaginationContent>
                        <PaginationItem>
                            {prevUrl && !onFirst ? (
                                <Link
                                    href={prevUrl}
                                    preserveState
                                    preserveScroll
                                    aria-label={t('Go to previous page')}
                                    className={cn(
                                        buttonVariants({
                                            variant: 'ghost',
                                            size: 'default',
                                        }),
                                        'gap-1 px-2.5 sm:pl-2.5',
                                    )}
                                >
                                    <ChevronLeftIcon />
                                    <span className="hidden sm:block">
                                        {t('Previous')}
                                    </span>
                                </Link>
                            ) : (
                                <span
                                    aria-disabled
                                    className={cn(
                                        buttonVariants({
                                            variant: 'ghost',
                                            size: 'default',
                                        }),
                                        'pointer-events-none gap-1 px-2.5 opacity-50 sm:pl-2.5',
                                    )}
                                >
                                    <ChevronLeftIcon />
                                    <span className="hidden sm:block">
                                        {t('Previous')}
                                    </span>
                                </span>
                            )}
                        </PaginationItem>

                        <PaginationItem>
                            {nextUrl && !onLast ? (
                                <Link
                                    href={nextUrl}
                                    preserveState
                                    preserveScroll
                                    aria-label={t('Go to next page')}
                                    className={cn(
                                        buttonVariants({
                                            variant: 'ghost',
                                            size: 'default',
                                        }),
                                        'gap-1 px-2.5 sm:pr-2.5',
                                    )}
                                >
                                    <span className="hidden sm:block">
                                        {t('Next')}
                                    </span>
                                    <ChevronRightIcon />
                                </Link>
                            ) : (
                                <span
                                    aria-disabled
                                    className={cn(
                                        buttonVariants({
                                            variant: 'ghost',
                                            size: 'default',
                                        }),
                                        'pointer-events-none gap-1 px-2.5 opacity-50 sm:pr-2.5',
                                    )}
                                >
                                    <span className="hidden sm:block">
                                        {t('Next')}
                                    </span>
                                    <ChevronRightIcon />
                                </span>
                            )}
                        </PaginationItem>
                    </PaginationContent>
                </Pagination>
            ) : null}
        </div>
    );
}
