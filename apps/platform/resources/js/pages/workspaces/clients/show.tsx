import { Head, setLayoutProps } from '@inertiajs/react';
import { Archive, FolderOpen, Mail, Pencil } from 'lucide-react';
import IndexPagination from '@/components/data/index-query/index-pagination';
import Heading from '@/components/primitives/heading';
import { LinkButton } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import ArchiveClientButton from '@/features/clients/manage/archive-client-button';
import ClientPortalLinkCard from '@/features/clients/portal/client-portal-link-card';
import type { ClientDetails } from '@/features/clients/types';
import DossiersListCard from '@/features/dossiers/list/dossiers-list-card';
import type { DossierSummary } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    index as clientIndex,
    edit as editClient,
    show as showClient,
} from '@/routes/workspaces/clients';
import {
    archived as archivedDossiers,
    create as createDossier,
} from '@/routes/workspaces/dossiers';
import type { Paginated } from '@/types/pagination';

export default function ShowClient({
    client,
    dossiers,
    portal_links: portalLinks,
    can_grant_portal_access: canGrantPortalAccess,
    access_grant_portal_url: accessGrantPortalUrl = null,
}: {
    client: ClientDetails;
    dossiers: Paginated<DossierSummary>;
    portal_links: App.Clients.Data.ClientPortalLinkData[];
    can_grant_portal_access: boolean;
    /** Flashed once by the controller; the plaintext is never stored. */
    access_grant_portal_url?: string | null;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            { title: t('Clients'), href: clientIndex(currentWorkspace) },
            {
                title: client.name,
                href: showClient({
                    tenant: currentWorkspace,
                    client: client.id,
                }),
            },
        ],
    });

    return (
        <>
            <Head title={client.name} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 items-start gap-4">
                        <div className="bg-secondary text-primary flex size-12 shrink-0 items-center justify-center rounded-xl border">
                            <span className="text-lg font-semibold" aria-hidden>
                                {client.name.slice(0, 1).toUpperCase()}
                            </span>
                        </div>
                        <div className="min-w-0">
                            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                                {t('Client record')}
                            </p>
                            <Heading
                                level={1}
                                className="mb-0"
                                title={client.name}
                            />
                            <p className="text-muted-foreground mt-1 flex items-center gap-2 text-sm">
                                <Mail className="size-4" aria-hidden="true" />
                                {client.email}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <LinkButton
                            variant="outline"
                            href={
                                editClient({
                                    tenant: currentWorkspace,
                                    client: client.id,
                                }).url
                            }
                        >
                            <Pencil aria-hidden="true" />
                            {t('Edit client')}
                        </LinkButton>
                        <LinkButton href={createDossier(currentWorkspace).url}>
                            {t('New dossier')}
                        </LinkButton>
                        <ArchiveClientButton
                            clientId={client.id}
                            clientName={client.name}
                            dossiersCount={client.active_dossiers_count}
                        />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="flex items-center gap-4 p-5">
                            <div className="bg-secondary text-primary flex size-10 items-center justify-center rounded-lg">
                                <FolderOpen className="size-5" aria-hidden />
                            </div>
                            <div>
                                <p className="text-2xl font-semibold tabular-nums">
                                    {client.active_dossiers_count}
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    {t('Active dossiers')}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="flex items-center justify-between gap-4 p-5">
                            <div className="flex items-center gap-4">
                                <div className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-lg">
                                    <Archive className="size-5" aria-hidden />
                                </div>
                                <div>
                                    <p className="text-2xl font-semibold tabular-nums">
                                        {client.archived_dossiers_count}
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {t('Archived dossiers')}
                                    </p>
                                </div>
                            </div>
                            {client.archived_dossiers_count > 0 ? (
                                <LinkButton
                                    variant="ghost"
                                    size="sm"
                                    href={
                                        archivedDossiers(currentWorkspace, {
                                            query: {
                                                filter: { q: client.name },
                                            },
                                        }).url
                                    }
                                >
                                    {t('View archive')}
                                </LinkButton>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                <ClientPortalLinkCard
                    clientId={client.id}
                    clientEmail={client.email}
                    links={portalLinks}
                    canCreate={canGrantPortalAccess}
                    portalUrl={accessGrantPortalUrl}
                />

                <DossiersListCard dossiers={dossiers} />
                <IndexPagination paginator={dossiers} />
            </div>
        </>
    );
}
