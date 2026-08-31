import { Link } from '@inertiajs/react';
import IndexList from '@/components/data/index-list';
import EmptyState from '@/components/primitives/empty-state';
import { LinkButton } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { DashboardRecentDossier } from '@/features/dashboard/types';
import DossierStatusBadge from '@/features/dossiers/status/dossier-status-badge';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import {
    create as createDossier,
    index as dossierIndex,
    show as showDossier,
} from '@/routes/workspaces/dossiers';

/**
 * Recently updated dossiers list for the workspace dashboard.
 */
export default function RecentDossiersCard({
    dossiers,
}: {
    dossiers: DashboardRecentDossier[];
}) {
    const currentWorkspace = useCurrentWorkspace();
    // Locale-aware UTC formatting — matches dossier index and avoids SSR/local drift.
    const { t } = useTranslation();
    const { formatDate } = useFormatters();

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div>
                    <CardTitle>{t('Recently updated dossiers')}</CardTitle>
                    <CardDescription>
                        {t('The latest activity across your firm.')}
                    </CardDescription>
                </div>
                <LinkButton
                    variant="ghost"
                    size="sm"
                    href={dossierIndex(currentWorkspace).url}
                >
                    {t('View all')}
                </LinkButton>
            </CardHeader>
            <CardContent>
                {dossiers.length === 0 ? (
                    <EmptyState
                        title={t(
                            'Create a dossier to start collecting documents.',
                        )}
                        action={
                            <LinkButton
                                size="sm"
                                href={createDossier(currentWorkspace).url}
                            >
                                {t('New dossier')}
                            </LinkButton>
                        }
                    />
                ) : (
                    <IndexList>
                        {dossiers.map((dossier) => (
                            <Link
                                key={dossier.id}
                                href={showDossier({
                                    tenant: currentWorkspace,
                                    dossier: dossier.id,
                                })}
                                className="hover:bg-muted/50 flex flex-wrap items-center justify-between gap-3 px-4 py-3 transition-colors"
                            >
                                <div>
                                    <p className="font-medium">
                                        {dossier.title}
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {dossier.client_name}
                                        {dossier.reference &&
                                            ` · ${dossier.reference}`}
                                    </p>
                                    {(dossier.due_date ||
                                        dossier.responsible_name) && (
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {dossier.due_date
                                                ? t('Due :date', {
                                                      date: formatDate(
                                                          dossier.due_date,
                                                      ),
                                                  })
                                                : t('No due date')}
                                            {dossier.responsible_name
                                                ? ` · ${dossier.responsible_name}`
                                                : null}
                                        </p>
                                    )}
                                </div>
                                <DossierStatusBadge status={dossier.status} />
                            </Link>
                        ))}
                    </IndexList>
                )}
            </CardContent>
        </Card>
    );
}
