import { Head, setLayoutProps } from '@inertiajs/react';
import IndexQueryResults from '@/components/data/index-query/index-query-results';
import IndexQueryToolbar from '@/components/data/index-query/index-query-toolbar';
import SimpleIndexPagination from '@/components/data/index-query/simple-index-pagination';
import Heading from '@/components/primitives/heading';
import ActivityLogCard from '@/features/audit/activity-log-card';
import type { ActivityLogEntry } from '@/features/audit/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as activityIndex } from '@/routes/workspaces/activity';
import type { IndexQueryConfig, SimplePaginated } from '@/types/pagination';

export default function ActivityLogIndex({
    activities,
    indexQuery,
}: {
    activities: SimplePaginated<ActivityLogEntry>;
    indexQuery: IndexQueryConfig;
    /** Current Spatie filter bag — consumed by useIndexQuery via usePage(). */
    filters?: Record<string, string>;
    sort?: string | null;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Activity'),
                href: activityIndex(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Activity')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <Heading
                    level={1}
                    className="mb-0"
                    title={t('Activity')}
                    description={t('Recent actions by your team.')}
                />

                <IndexQueryToolbar config={indexQuery} />
                <IndexQueryResults className="space-y-6">
                    <ActivityLogCard activities={activities} />
                    <SimpleIndexPagination paginator={activities} />
                </IndexQueryResults>
            </div>
        </>
    );
}
