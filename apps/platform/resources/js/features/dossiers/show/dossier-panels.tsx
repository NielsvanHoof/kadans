import { Deferred, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import AudioRecordingsCard from '@/features/dossiers/show/audio-recordings-card';
import DossierExportCard from '@/features/dossiers/show/dossier-export-card';
import {
    type DossierPanel,
    validAccessGrants,
} from '@/features/dossiers/show/dossier-panel';
import WaitingItemsSummary from '@/features/dossiers/show/waiting-items-summary';
import type { Dossier, DossierExport } from '@/features/dossiers/types';
import ClientAccessCard from '@/features/portal/client-access-card';
import PortalLinkBanner from '@/features/portal/portal-link-banner';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The dossier workbench: four panels behind one pill tab strip.
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
        <Card className="min-w-0 gap-0 overflow-hidden py-0">
            <Tabs
                selectedKey={activePanel}
                onSelectionChange={(key) =>
                    onSelectPanel(String(key) as DossierPanel)
                }
                className="gap-0"
            >
                <CardHeader className="bg-card sticky top-0 z-10 touch-manipulation border-b py-3 lg:static">
                    <ScrollArea className="w-full">
                        <TabsList aria-label={t('Dossier panels')}>
                            <TabsTrigger
                                id="waiting"
                                className="gap-1.5 text-sm"
                            >
                                {t('Waiting')}
                                <PanelCount
                                    value={
                                        dossier.review_summary.pending +
                                        dossier.review_summary.rejected
                                    }
                                />
                            </TabsTrigger>
                            <TabsTrigger
                                id="access"
                                className="gap-1.5 text-sm"
                            >
                                {t('Access')}
                                <PanelCount
                                    value={
                                        validAccessGrants(dossier.access_grants)
                                            .length
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
                                <TabsTrigger
                                    id="exports"
                                    className="gap-1.5 text-sm"
                                >
                                    {t('Exports')}
                                    <PanelCount value={dossier.exports_count} />
                                </TabsTrigger>
                            ) : null}
                        </TabsList>
                    </ScrollArea>
                </CardHeader>

                {/*
                 * No lead here on purpose: the tab already says Waiting and the
                 * rows below say what is waiting. The other panels each explain
                 * something their body cannot.
                 */}
                <CardContent className="py-5">
                    <TabsContent
                        id="waiting"
                        className="scroll-mt-20 outline-none"
                    >
                        <Deferred
                            data="document_requests"
                            fallback={<WaitingItemsSkeleton />}
                        >
                            <DeferredWaitingItemsSummary
                                dossier={dossier}
                                onSelectAccess={() => onSelectPanel('access')}
                            />
                        </Deferred>
                    </TabsContent>

                    <TabsContent
                        id="access"
                        className="scroll-mt-20 outline-none"
                    >
                        <PanelBody
                            lead={t(
                                'Email a magic link so :name can upload documents without an account.',
                                { name: dossier.client.name },
                            )}
                        >
                            {accessGrantPortalUrl ? (
                                <PortalLinkBanner
                                    portalUrl={accessGrantPortalUrl}
                                />
                            ) : null}

                            {!hasFormItems ? (
                                <p className="bg-muted/30 text-muted-foreground rounded-md border px-3 py-2 text-sm">
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
                        <TabsContent
                            id="recordings"
                            className="scroll-mt-20 outline-none"
                        >
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
                        <TabsContent
                            id="exports"
                            className="scroll-mt-20 outline-none"
                        >
                            <PanelBody
                                lead={t(
                                    'Create a secure ZIP archive containing the dossier answers and uploaded documents.',
                                )}
                            >
                                <Deferred
                                    data="exports"
                                    fallback={<ExportsSkeleton />}
                                >
                                    <DeferredExportCard dossier={dossier} />
                                </Deferred>
                            </PanelBody>
                        </TabsContent>
                    ) : null}
                </CardContent>
            </Tabs>
        </Card>
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
            <p className="text-muted-foreground max-w-prose text-sm">{lead}</p>
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

function DeferredWaitingItemsSummary({
    dossier,
    onSelectAccess,
}: {
    dossier: Dossier;
    onSelectAccess: () => void;
}) {
    const documentRequests = usePage().props
        .document_requests as DocumentRequest[];

    return (
        <WaitingItemsSummary
            dossier={{
                ...dossier,
                document_requests: documentRequests,
            }}
            onSelectAccess={onSelectAccess}
        />
    );
}

/** Pulsing placeholder while the deferred `document_requests` prop resolves. */
function WaitingItemsSkeleton() {
    return (
        <div aria-busy="true" className="space-y-3">
            <Skeleton className="h-9 w-48" />
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
        </div>
    );
}
