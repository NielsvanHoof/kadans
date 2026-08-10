import { Head, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/primitives/heading';
import { useReloadOnDataSubjectExportCompleted } from '@/features/data-subject-requests/hooks/use-reload-on-data-subject-export-completed';
import LogRequestForm from '@/features/data-subject-requests/log-request-form';
import RequestsListCard from '@/features/data-subject-requests/requests-list-card';
import type { DataSubjectRequest } from '@/features/data-subject-requests/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as requestIndex } from '@/routes/workspaces/data-subject-requests';

/**
 * Data-subject requests — the firm's record of clients exercising their access
 * and erasure rights, and the deadline for answering each one.
 */
export default function DataSubjectRequestIndex({
    requests,
    can_manage: canManage = false,
}: {
    requests: DataSubjectRequest[];
    can_manage?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    // Queued exports finish off-request; reload so Download appears without F5.
    useReloadOnDataSubjectExportCompleted();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Data-subject requests'),
                href: requestIndex(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Data-subject requests')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <Heading
                    level={1}
                    className="mb-0"
                    title={t('Data-subject requests')}
                    description={t(
                        'Track requests from clients for a copy of their data or for its erasure, and the deadline for answering each one.',
                    )}
                />

                {canManage ? <LogRequestForm /> : null}

                <RequestsListCard requests={requests} canManage={canManage} />

                <p className="text-sm text-muted-foreground">
                    {t(
                        'Erasure removes the client’s name, email, portal access and activity trail. Dossiers, documents and transcripts are kept, because the firm is legally required to retain them — so those records may still identify the person. Say so when you answer the client.',
                    )}
                </p>
            </div>
        </>
    );
}
