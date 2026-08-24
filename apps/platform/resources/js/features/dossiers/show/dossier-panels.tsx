import { Deferred, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import AudioRecordingsCard from '@/features/dossiers/show/audio-recordings-card';
import DossierExportCard from '@/features/dossiers/show/dossier-export-card';
import {
    type DossierPanel,
    validAccessGrants,
    waitingDocumentRequests,
} from '@/features/dossiers/show/dossier-panel';
import WaitingItemsSummary from '@/features/dossiers/show/waiting-items-summary';
import type { Dossier, DossierExport } from '@/features/dossiers/types';
import ClientAccessCard from '@/features/portal/client-access-card';
import PortalLinkBanner from '@/features/portal/portal-link-banner';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The dossier workbench: four panels behind one tab strip.
 *
 * The strip doubles as a status readout — every label carries its own count in
 * mono numerals, so "what is in this dossier" is answered without opening
 * anything. Counts are omitted at zero: a number appears only when there is
 * something behind it.
 *
 * Panel visibility comes from the synchronous `*_count` props, never from the
 * deferred rows, so the strip cannot change shape after first paint.
 */
export default function DossierPanels({
    dossier,
    panel,
    onSelectPanel,
    accessGrantToken,
    accessGrantPortalUrl,
    hasFormItems,
}: {
    dossier: Dossier;
    panel: DossierPanel;
    onSelectPanel: (panel: DossierPanel) => void;
    accessGrantToken: string | null;
    accessGrantPortalUrl: string | null;
    hasFormItems: boolean;
}) {
    const { t } = useTranslation();
    const { canDownload, canCreateInvite, canUploadAudio, canDownloadAudio } =
        useDossierCapabilities();

    const showRecordings = canUploadAudio || dossier.audio_recordings_count > 0;
    const showExports = canDownload;

    const visiblePanels: DossierPanel[] = [
        'waiting',
        'access',
        ...(showRecordings ? (['recordings'] as const) : []),
        ...(showExports ? (['exports'] as const) : []),
    ];

    // A remembered or hand-typed panel may no longer be available to this user.
    const activePanel = visiblePanels.includes(panel) ? panel : 'waiting';

    return (
        <Tabs
            selectedKey={activePanel}
            onSelectionChange={(key) =>
                onSelectPanel(String(key) as DossierPanel)
            }
        >
            <div className="-mx-4 overflow-x-auto border-b border-ledger-rule px-4 pb-1.5 md:mx-0 md:px-0">
                <TabsList variant="line" aria-label={t('Dossier panels')}>
                    <TabsTrigger id="waiting" className="gap-1.5 text-sm">
                        {t('Waiting')}
                        <PanelCount
                            value={
                                waitingDocumentRequests(
                                    dossier.document_requests,
                                ).length
                            }
                        />
                    </TabsTrigger>
                    <TabsTrigger id="access" className="gap-1.5 text-sm">
                        {t('Access')}
                        <PanelCount
                            value={
                                validAccessGrants(dossier.access_grants).length
                            }
                        />
                    </TabsTrigger>
                    {showRecordings ? (
                        <TabsTrigger
                            id="recordings"
                            className="gap-1.5 text-sm"
                        >
                            {t('Recordings')}
                            <PanelCount
                                value={dossier.audio_recordings_count}
                            />
                        </TabsTrigger>
                    ) : null}
                    {showExports ? (
                        <TabsTrigger id="exports" className="gap-1.5 text-sm">
                            {t('Exports')}
                            <PanelCount value={dossier.exports_count} />
                        </TabsTrigger>
                    ) : null}
                </TabsList>
            </div>

            {/*
             * No lead here on purpose: the tab already says Waiting and the rows
             * below say what is waiting. The other panels each explain something
             * their body cannot.
             */}
            <TabsContent id="waiting" className="pt-5">
                <WaitingItemsSummary
                    dossier={dossier}
                    onSelectAccess={() => onSelectPanel('access')}
                />
            </TabsContent>

            <TabsContent id="access" className="pt-5">
                <PanelBody
                    lead={t(
                        'Email a magic link so :name can upload documents without an account.',
                        { name: dossier.client.name },
                    )}
                >
                    {accessGrantPortalUrl ? (
                        <PortalLinkBanner
                            portalUrl={accessGrantPortalUrl}
                            token={accessGrantToken}
                        />
                    ) : null}

                    {!hasFormItems ? (
                        <p className="rounded-md border border-ledger-rule bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                            {t(
                                'Add at least one form field before inviting the client.',
                            )}
                        </p>
                    ) : null}

                    <ClientAccessCard
                        dossierId={dossier.id}
                        clientEmail={dossier.client.email}
                        accessGrants={dossier.access_grants}
                        canCreate={canCreateInvite}
                    />
                </PanelBody>
            </TabsContent>

            {showRecordings ? (
                <TabsContent id="recordings" className="pt-5">
                    <PanelBody
                        lead={t(
                            'Upload a call recording or voice note to have it transcribed automatically.',
                        )}
                    >
                        <AudioRecordingsCard
                            dossier={dossier}
                            canUpload={canUploadAudio}
                            canDownload={canDownloadAudio}
                        />
                    </PanelBody>
                </TabsContent>
            ) : null}

            {showExports ? (
                <TabsContent id="exports" className="pt-5">
                    <PanelBody
                        lead={t(
                            'Create a secure ZIP archive containing the dossier answers and uploaded documents.',
                        )}
                    >
                        <Deferred data="exports" fallback={<ExportsSkeleton />}>
                            <DeferredExportCard dossier={dossier} />
                        </Deferred>
                    </PanelBody>
                </TabsContent>
            ) : null}
        </Tabs>
    );
}

/** Mono numeral beside a tab label; absent when there is nothing to count. */
function PanelCount({ value }: { value: number }) {
    if (value === 0) {
        return null;
    }

    return (
        <span className="font-data text-[0.6875rem] tabular-nums opacity-70">
            {value}
        </span>
    );
}

function PanelBody({
    lead,
    children,
}: {
    lead: string;
    children: ReactNode;
}): ReactNode {
    return (
        <div className="space-y-4 text-sm">
            <p className="max-w-prose text-sm text-muted-foreground">{lead}</p>
            {children}
        </div>
    );
}

/** Pulsing placeholder while the deferred `exports` prop resolves. */
function ExportsSkeleton() {
    return (
        <div aria-busy="true" className="space-y-3">
            <Skeleton className="h-9 w-36" />
            <Skeleton className="h-16 w-full" />
        </div>
    );
}

function DeferredExportCard({ dossier }: { dossier: Dossier }) {
    const exports = usePage().props.exports as DossierExport[];

    return (
        <DossierExportCard
            dossier={{
                ...dossier,
                exports,
            }}
        />
    );
}
