import type { InertiaLinkProps } from '@inertiajs/react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * Wayfinder route helpers often return `{ url, method }` objects.
 * React keys and string compares need the resolved URL string.
 */
function resolveHref(href: NonNullable<InertiaLinkProps['href']>): string {
    if (typeof href === 'string') {
        return href;
    }

    if (typeof href === 'object' && href !== null && 'url' in href) {
        return String(href.url);
    }

    return String(href);
}

export function Breadcrumbs({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    return (
        <>
            {breadcrumbs.length > 0 && (
                <Breadcrumb>
                    <BreadcrumbList>
                        {breadcrumbs.map((item, index) => {
                            const href = resolveHref(item.href);
                            const isLast = index === breadcrumbs.length - 1;

                            return (
                                <Fragment key={`${item.title}-${href}`}>
                                    <BreadcrumbItem>
                                        {isLast ? (
                                            <BreadcrumbPage>
                                                {item.title}
                                            </BreadcrumbPage>
                                        ) : (
                                            <BreadcrumbLink
                                                href={
                                                    typeof item.href ===
                                                    'string'
                                                        ? item.href
                                                        : item.href.url
                                                }
                                            >
                                                {item.title}
                                            </BreadcrumbLink>
                                        )}
                                    </BreadcrumbItem>
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
