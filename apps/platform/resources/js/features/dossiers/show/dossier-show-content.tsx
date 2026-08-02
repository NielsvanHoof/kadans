import { ArrowRight, Mail, Plus } from 'lucide-react';
import Heading from '@/components/primitives/heading';
import { buttonVariants, LinkButton } from '@/components/ui/button';
import DossierFollowUpCard from '@/features/dossiers/follow-up/dossier-follow-up-card';
import {
    DossierPermissionsProvider,
    useDossierCapabilities,
} from '@/features/dossiers/permissions/dossier-permissions-context';
import DossierActionsMenu from '@/features/dossiers/show/dossier-actions-menu';
import DossierExportCard from '@/features/dossiers/show/dossier-export-card';
import DossierWorkflowLinks from '@/features/dossiers/show/dossier-workflow-links';
import WaitingItemsSummary from '@/features/dossiers/show/waiting-items-summary';
import DossierStatusBadge from '@/features/dossiers/status/dossier-status-badge';
import type { Dossier } from '@/features/dossiers/types';
import ClientAccessCard from '@/features/portal/client-access-card';
import PortalLinkBanner from '@/features/portal/portal-link-banner';
import type { Firm } from '@/features/workspaces/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    builder as builderDossier,
    review as reviewDossier,
} from '@/routes/workspaces/dossiers';

type DossierShowContentProps = {
    dossier: Dossier;
    accessGrantToken?: string | null;
    accessGrantPortalUrl?: string | null;
    canManage: boolean;
    canEditDetails: boolean;
    canSendReminder: boolean;
    canReview: boolean;
    canDownload: boolean;
};

type PrimaryAction =
    | { kind: 'link'; label: string; href: string }
    | { kind: 'anchor'; label: string; href: string };

/**
 * Staff dossier overview hub — follow-up, invite, and links out to builder/review.
 */
export default function DossierShowContent({
    dossier,
    accessGrantToken = null,
    accessGrantPortalUrl = null,
    canManage,
    canEditDetails,
    canSendReminder,
    canReview,
    canDownload,
}: DossierShowContentProps) {
    const hasFormItems = dossier.document_requests.length > 0;

    return (
        <DossierPermissionsProvider
            canManage={canManage}
            canReview={canReview}
            canDownload={canDownload}
            canEditDetails={canEditDetails}
            canSendReminder={canSendReminder}
            dossierStatus={dossier.status}
            hasFormItems={hasFormItems}
        >
            <DossierShowBody
                dossier={dossier}
                accessGrantToken={accessGrantToken}
                accessGrantPortalUrl={accessGrantPortalUrl}
                hasFormItems={hasFormItems}
            />
        </DossierPermissionsProvider>
    );
}

function resolvePrimaryAction(
    dossier: Dossier,
    canManage: boolean,
    canReview: boolean,
    t: (key: string) => string,
    routeArgs: { tenant: Firm; dossier: number },
): PrimaryAction | null {
    if (!canManage || dossier.status === 'completed') {
        return null;
    }

    const hasFormItems = dossier.document_requests.length > 0;
    const hasReadyForReview = dossier.document_requests.some(
        (request) => request.status === 'submitted',
    );
    const hasValidInvite = dossier.access_grants.some(
        (grant) => grant.is_valid,
    );

    if (!hasFormItems) {
        return {
            kind: 'link',
            label: t('Build form'),
            href: builderDossier(routeArgs).url,
        };
    }

    if (hasReadyForReview && canReview) {
        return {
            kind: 'link',
            label: t('Review submissions'),
            href: reviewDossier(routeArgs).url,
        };
    }

    if (!hasValidInvite) {
        return {
            kind: 'anchor',
            label: t('Invite client'),
            href: '#dossier-invite',
        };
    }

    return null;
}

function DossierShowBody({
    dossier,
    accessGrantToken,
    accessGrantPortalUrl,
    hasFormItems,
}: {
    dossier: Dossier;
    accessGrantToken: string | null;
    accessGrantPortalUrl: string | null;
    hasFormItems: boolean;
}) {
    const { t } = useTranslation();
    const {
        canManage,
        canReview,
        canDownload,
        canEditDetails,
        canCreateInvite,
    } = useDossierCapabilities();
    const currentWorkspace = useCurrentWorkspace();

    const routeArgs = {
        tenant: currentWorkspace,
        dossier: dossier.id,
    };

    const primaryAction = resolvePrimaryAction(
        dossier,
        canManage,
        canReview,
        t,
        routeArgs,
    );

    return (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    level={1}
                    className="mb-0"
                    title={dossier.title}
                    description={`${dossier.client.name} · ${dossier.client.email}`}
                    badge={<DossierStatusBadge status={dossier.status} />}
                />
                <div className="flex flex-wrap items-center gap-2">
                    {primaryAction ? (
                        primaryAction.kind === 'link' ? (
                            <LinkButton href={primaryAction.href}>
                                {primaryAction.label === t('Build form') ? (
                                    <Plus aria-hidden />
                                ) : (
                                    <ArrowRight aria-hidden />
                                )}
                                {primaryAction.label}
                            </LinkButton>
                        ) : (
                            // Plain anchor: this is an in-page `#…` jump, and
                            // RouterProvider would hand it to Inertia.
                            <a
                                className={buttonVariants()}
                                href={primaryAction.href}
                            >
                                <Mail aria-hidden />
                                {primaryAction.label}
                            </a>
                        )
                    ) : null}
                    <DossierActionsMenu
                        dossierId={dossier.id}
                        dossierTitle={dossier.title}
                        canEditDetails={canEditDetails}
                        canManage={canManage}
                    />
                </div>
            </div>

            <DossierFollowUpCard dossier={dossier} />

            {accessGrantPortalUrl ? (
                <PortalLinkBanner
                    portalUrl={accessGrantPortalUrl}
                    token={accessGrantToken}
                />
            ) : null}

            <DossierWorkflowLinks dossier={dossier} />

            {canDownload ? <DossierExportCard dossier={dossier} /> : null}

            <section
                id="dossier-invite"
                className="grid scroll-mt-6 gap-6 lg:grid-cols-[1fr_20rem]"
                aria-labelledby="dossier-invite-heading"
            >
                <div className="space-y-3">
                    <h2
                        id="dossier-invite-heading"
                        className="text-base font-semibold tracking-tight"
                    >
                        {t('Send Invite')}
                    </h2>
                    {!hasFormItems ? (
                        <p className="rounded-md border border-border/70 bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                            {t(
                                'Add at least one form field before inviting the client.',
                            )}
                        </p>
                    ) : null}
                    <ClientAccessCard
                        dossierId={dossier.id}
                        clientName={dossier.client.name}
                        clientEmail={dossier.client.email}
                        accessGrants={dossier.access_grants}
                        canCreate={canCreateInvite}
                    />
                </div>
                <WaitingItemsSummary
                    documentRequests={dossier.document_requests}
                />
            </section>
        </div>
    );
}
