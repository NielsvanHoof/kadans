import { ArrowRight, Mail, Plus } from 'lucide-react';
import Heading from '@/components/primitives/heading';
import { Button, LinkButton } from '@/components/ui/button';
import {
    DossierPermissionsProvider,
    useDossierCapabilities,
} from '@/features/dossiers/permissions/dossier-permissions-context';
import DossierActionsMenu from '@/features/dossiers/show/dossier-actions-menu';
import DossierContextRail, {
    DossierContextRailBody,
} from '@/features/dossiers/show/dossier-context-rail';
import {
    type DossierPanel,
    isDossierPanel,
} from '@/features/dossiers/show/dossier-panel';
import DossierPanels from '@/features/dossiers/show/dossier-panels';
import DossierStateBand from '@/features/dossiers/show/dossier-state-band';
import DossierStatusSheet from '@/features/dossiers/show/dossier-status-sheet';
import DossierStatusBadge from '@/features/dossiers/status/dossier-status-badge';
import type { Dossier } from '@/features/dossiers/types';
import type { Firm } from '@/features/workspaces/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { useUrlTab } from '@/hooks/use-url-tab';
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
    canUploadAudio: boolean;
    canDownloadAudio: boolean;
};

type PrimaryAction =
    | { kind: 'link'; label: string; href: string }
    | { kind: 'panel'; label: string; panel: DossierPanel };

/**
 * Staff dossier overview — a compact stepper over a workbench and status rail.
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
    canUploadAudio,
    canDownloadAudio,
}: DossierShowContentProps) {
    const hasFormItems = dossier.review_summary.total > 0;

    return (
        <DossierPermissionsProvider
            canManage={canManage}
            canReview={canReview}
            canDownload={canDownload}
            canEditDetails={canEditDetails}
            canSendReminder={canSendReminder}
            canUploadAudio={canUploadAudio}
            canDownloadAudio={canDownloadAudio}
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

    const hasFormItems = dossier.review_summary.total > 0;
    const hasReadyForReview = dossier.review_summary.submitted > 0;
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
            kind: 'panel',
            label: t('Invite client'),
            panel: 'access',
        };
    }

    return null;
}

/**
 * Which panel opens when the URL does not name one.
 *
 * A freshly created portal link is shown once and cannot be recovered, so the
 * redirect that carries it must land on the panel that displays it. A completed
 * dossier has nothing left to chase — the archive is the only useful action.
 */
function defaultPanel(
    dossier: Dossier,
    accessGrantPortalUrl: string | null,
    canDownload: boolean,
): DossierPanel {
    if (accessGrantPortalUrl !== null) {
        return 'access';
    }

    if (dossier.status === 'completed' && canDownload) {
        return 'exports';
    }

    return 'waiting';
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
    const { canManage, canReview, canDownload, canEditDetails } =
        useDossierCapabilities();
    const currentWorkspace = useCurrentWorkspace();

    // Panel state lives here, above every panel body, and is seeded from the URL
    // exactly once. A rejected audio upload redirects back to a URL with no
    // ?tab=, and re-deriving the panel from that URL would unmount the upload
    // form before its inline error could paint (TASK-42).
    const [panel, setPanel] = useUrlTab<DossierPanel>(
        'tab',
        isDossierPanel,
        defaultPanel(dossier, accessGrantPortalUrl, canDownload),
    );

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
        <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] md:p-8">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    {/*
                     * The reference, not a decorative eyebrow: it is how the firm
                     * names a dossier, and font-data carries slashed-zero so 0
                     * and O stay apart in codes like this one.
                     */}
                    {dossier.reference ? (
                        <p className="font-data text-muted-foreground mb-1 text-xs tracking-[0.08em] uppercase">
                            {dossier.reference}
                        </p>
                    ) : null}
                    <Heading
                        level={1}
                        className="mb-0"
                        title={dossier.title}
                        description={`${dossier.client.name} · ${dossier.client.email}`}
                        badge={<DossierStatusBadge status={dossier.status} />}
                    />
                </div>
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
                            <Button
                                type="button"
                                onClick={() => setPanel(primaryAction.panel)}
                            >
                                <Mail aria-hidden />
                                {primaryAction.label}
                            </Button>
                        )
                    ) : null}
                    <DossierStatusSheet>
                        <DossierContextRailBody dossier={dossier} />
                    </DossierStatusSheet>
                    <DossierActionsMenu
                        dossierId={dossier.id}
                        dossierTitle={dossier.title}
                        canEditDetails={canEditDetails}
                        canManage={canManage}
                    />
                </div>
            </div>

            <DossierStateBand
                dossier={dossier}
                onSelectInvite={() => setPanel('access')}
            />

            <div className="grid min-w-0 gap-6 lg:grid-cols-12">
                <div className="min-w-0 lg:col-span-8">
                    <DossierPanels
                        dossier={dossier}
                        panel={panel}
                        onSelectPanel={setPanel}
                        accessGrantToken={accessGrantToken}
                        accessGrantPortalUrl={accessGrantPortalUrl}
                        hasFormItems={hasFormItems}
                    />
                </div>
                <DossierContextRail
                    dossier={dossier}
                    className="hidden self-start lg:sticky lg:top-4 lg:col-span-4 lg:block"
                />
            </div>
        </div>
    );
}
