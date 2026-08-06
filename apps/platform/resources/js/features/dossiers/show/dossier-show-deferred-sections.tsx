import { Deferred, usePage } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardHeader,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { AudioRecording } from '@/features/audio-recordings/types';
import AudioRecordingsCard from '@/features/dossiers/show/audio-recordings-card';
import DossierExportCard from '@/features/dossiers/show/dossier-export-card';
import type { Dossier, DossierExport } from '@/features/dossiers/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Pulsing placeholders while deferred export / audio props resolve.
 */
function BelowFoldSkeleton({ label }: { label: string }) {
    return (
        <Card aria-busy="true" aria-label={label}>
            <CardHeader className="space-y-2">
                <Skeleton className="h-5 w-40" />
                <Skeleton className="h-4 w-64" />
            </CardHeader>
            <CardContent className="space-y-3">
                <Skeleton className="h-10 w-full" />
                <Skeleton className="h-10 w-full" />
            </CardContent>
        </Card>
    );
}

/**
 * Below-the-fold export + audio sections, loaded after first paint.
 */
export default function DossierShowDeferredSections({
    dossier,
    canDownload,
    canUploadAudio,
    canDownloadAudio,
}: {
    dossier: Dossier;
    canDownload: boolean;
    canUploadAudio: boolean;
    canDownloadAudio: boolean;
}) {
    const { t } = useTranslation();

    return (
        <>
            {canDownload ? (
                <Deferred
                    data="exports"
                    fallback={
                        <BelowFoldSkeleton label={t('Loading exports')} />
                    }
                >
                    <DeferredExportCard dossier={dossier} />
                </Deferred>
            ) : null}

            <Deferred
                data="audio_recordings"
                fallback={
                    <BelowFoldSkeleton
                        label={t('Loading audio recordings')}
                    />
                }
            >
                <DeferredAudioCard
                    dossier={dossier}
                    canUploadAudio={canUploadAudio}
                    canDownloadAudio={canDownloadAudio}
                />
            </Deferred>
        </>
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

function DeferredAudioCard({
    dossier,
    canUploadAudio,
    canDownloadAudio,
}: {
    dossier: Dossier;
    canUploadAudio: boolean;
    canDownloadAudio: boolean;
}) {
    const audioRecordings = usePage().props
        .audio_recordings as AudioRecording[];

    if (!canUploadAudio && audioRecordings.length === 0) {
        return null;
    }

    return (
        <AudioRecordingsCard
            dossier={{
                ...dossier,
                audio_recordings: audioRecordings,
            }}
            canUpload={canUploadAudio}
            canDownload={canDownloadAudio}
        />
    );
}
