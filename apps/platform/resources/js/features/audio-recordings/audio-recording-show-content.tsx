import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import AudioRecordingController from '@/actions/App/Dossiers/Http/Controllers/AudioRecordingController';
import Heading from '@/components/primitives/heading';
import { Badge } from '@/components/ui/badge';
import { buttonVariants, LinkButton } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import RecordingShowPanels from '@/features/audio-recordings/recording-show-panels';
import {
    isRecordingShowTab,
    type RecordingShowTab,
} from '@/features/audio-recordings/recording-show-tab';
import type { AudioRecordingShowProps } from '@/features/audio-recordings/types';
import { useFetchOnPipelineUpdated } from '@/features/pipelines/hooks/use-fetch-on-pipeline-updated';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { useUrlTab } from '@/hooks/use-url-tab';
import { formatBytes } from '@/lib/format-bytes';
import { show as showDossier } from '@/routes/workspaces/dossiers';

type AudioRecordingDetail = AudioRecordingShowProps['audio_recording'];

/**
 * Transcript page chrome: header, player, then tabbed transcript / calendar.
 * The player stays outside the tabs so switching panels does not stop playback.
 */
export default function AudioRecordingShowContent({
    audio_recording: initialAudioRecording,
    dossier,
    can_download: canDownload,
    calendar,
}: AudioRecordingShowProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();
    const [audioRecording, setAudioRecording] = useState(initialAudioRecording);
    const [currentTimeSeconds, setCurrentTimeSeconds] = useState(0);
    const [isAudioLoading, setIsAudioLoading] = useState(false);
    const [tab, setTab] = useUrlTab<RecordingShowTab>(
        'tab',
        isRecordingShowTab,
        'transcript',
    );

    // Sync when Inertia replaces the page prop (navigation / password confirm).
    useEffect(() => {
        setAudioRecording(initialAudioRecording);
    }, [initialAudioRecording]);

    const isProcessing =
        audioRecording.transcription_status === 'pending' ||
        audioRecording.transcription_status === 'processing';

    useFetchOnPipelineUpdated<{ audio_recording: AudioRecordingDetail }>({
        enabled: isProcessing,
        // The dossier is optional on this page; see the document page.
        scope: dossier
            ? { on: 'dossier', dossierId: dossier.id }
            : { on: 'workspace' },
        match: (event) =>
            event.subject_type === 'audio_recording' &&
            event.subject_id === audioRecording.id,
        urlForEvent: () =>
            AudioRecordingController.show.url({
                tenant: currentWorkspace,
                audioRecording: audioRecording.id,
            }),
        onData: ({ audio_recording: next }) => {
            if (
                audioRecording.transcription_status !== 'completed' &&
                next.transcription_status === 'completed'
            ) {
                toast.success(
                    t('Transcript ready for :file.', {
                        file: next.original_filename,
                    }),
                );
            }

            setAudioRecording(next);
        },
    });

    const downloadUrl = canDownload
        ? AudioRecordingController.download.url({
              tenant: currentWorkspace,
              audioRecording: audioRecording.id,
          })
        : null;

    useEffect(() => {
        setIsAudioLoading(downloadUrl !== null);
    }, [downloadUrl]);

    return (
        <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-8">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    level={1}
                    title={audioRecording.original_filename}
                    description={t('Transcript')}
                />
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">
                        {audioRecording.transcription_status.replaceAll(
                            '_',
                            ' ',
                        )}
                    </Badge>
                    {dossier && (
                        <LinkButton
                            variant="outline"
                            size="sm"
                            href={
                                showDossier({
                                    tenant: currentWorkspace,
                                    dossier: dossier.id,
                                }).url
                            }
                        >
                            {t('Back to dossier')}
                        </LinkButton>
                    )}
                    {canDownload && downloadUrl && (
                        <a
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                            href={downloadUrl}
                        >
                            {t('Download')}
                        </a>
                    )}
                </div>
            </div>

            <p className="font-data text-muted-foreground text-sm">
                {formatBytes(audioRecording.size_bytes)} · {t('uploaded')}{' '}
                {formatDateTime(audioRecording.uploaded_at)}
            </p>

            {downloadUrl && (
                <div className="flex flex-col gap-2">
                    {isAudioLoading && (
                        <p className="text-muted-foreground flex items-center gap-2 text-sm">
                            <Spinner className="size-4 shrink-0" />
                            {t('Loading audio stream...')}
                        </p>
                    )}
                    {/* biome-ignore lint/a11y/useMediaCaption: no caption track for staff-uploaded audio */}
                    <audio
                        controls
                        className="w-full"
                        src={downloadUrl}
                        onLoadStart={() => setIsAudioLoading(true)}
                        onCanPlay={() => setIsAudioLoading(false)}
                        onError={() => setIsAudioLoading(false)}
                        onTimeUpdate={(event) =>
                            setCurrentTimeSeconds(
                                event.currentTarget.currentTime,
                            )
                        }
                    >
                        {t('Your browser does not support audio playback.')}
                    </audio>
                </div>
            )}

            <RecordingShowPanels
                audioRecording={audioRecording}
                calendar={calendar}
                currentTimeSeconds={currentTimeSeconds}
                tab={tab}
                onSelectTab={setTab}
            />
        </div>
    );
}
