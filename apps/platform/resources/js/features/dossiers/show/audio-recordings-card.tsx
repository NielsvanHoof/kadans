import { Deferred, usePage } from '@inertiajs/react';
import { Download, Mic, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import AudioRecordingController from '@/actions/App/Dossiers/Http/Controllers/AudioRecordingController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button, buttonVariants, LinkButton } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import AudioRecordingUploadForm from '@/features/audio-recordings/audio-recording-upload-form';
import type { AudioRecording } from '@/features/audio-recordings/types';
import type { Dossier } from '@/features/dossiers/types';
import { useFetchOnPipelineUpdated } from '@/features/pipelines/hooks/use-fetch-on-pipeline-updated';
import {
    pipelineStepLabel,
    pipelineStepStatusLabel,
} from '@/features/pipelines/status/pipeline-status';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { show as showAudioRecording } from '@/routes/workspaces/audio-recordings';

/**
 * Staff audio recordings for a dossier. In-flight transcriptions refresh via
 * a JSON island, mirroring DossierExportCard's Reverb wiring.
 *
 * The <Deferred> boundary wraps only the list, never the upload form. A failed
 * upload redirects back, and that page object re-declares `audio_recordings` as
 * deferred, so anything inside the boundary unmounts while the prop reloads —
 * which used to destroy useForm's state and swallow the validation error before
 * it could paint (TASK-42).
 */
export default function AudioRecordingsCard({
    dossier,
    canUpload,
    canDownload,
}: {
    dossier: Dossier;
    canUpload: boolean;
    canDownload: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Mic aria-hidden="true" />
                    {t('Audio recordings')}
                </CardTitle>
                <CardDescription>
                    {t(
                        'Upload a call recording or voice note to have it transcribed automatically.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {canUpload ? (
                    <AudioRecordingUploadForm
                        inputId="audio-recording-upload"
                        className="mt-0"
                        actionUrl={AudioRecordingController.store.url({
                            tenant: currentWorkspace,
                            dossier: dossier.id,
                        })}
                    />
                ) : null}

                <Deferred
                    data="audio_recordings"
                    fallback={<RecordingsListSkeleton />}
                >
                    <AudioRecordingsList
                        dossierId={dossier.id}
                        canDownload={canDownload}
                    />
                </Deferred>
            </CardContent>
        </Card>
    );
}

/** Pulsing placeholder for the list while the deferred prop resolves. */
function RecordingsListSkeleton() {
    const { t } = useTranslation();

    return (
        <div
            aria-busy="true"
            aria-label={t('Loading audio recordings')}
            className="space-y-3 rounded-md border px-4 py-3"
        >
            <Skeleton className="h-4 w-48" />
            <Skeleton className="h-4 w-64" />
        </div>
    );
}

function AudioRecordingsList({
    dossierId,
    canDownload,
}: {
    dossierId: number;
    canDownload: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();
    const deferredRecordings = usePage().props
        .audio_recordings as AudioRecording[];
    const [recordings, setRecordings] = useState(deferredRecordings);

    // Sync when Inertia replaces the deferred prop (upload redirect / navigation).
    useEffect(() => {
        setRecordings(deferredRecordings);
    }, [deferredRecordings]);

    const hasInFlightProcessing = recordings.some(
        (recording) =>
            recording.transcription_status === 'pending' ||
            recording.transcription_status === 'processing',
    );

    useFetchOnPipelineUpdated<{
        audio_recording: (typeof recordings)[number];
    }>({
        enabled: hasInFlightProcessing,
        match: (event) =>
            event.dossier_id === dossierId &&
            event.type === 'audio_transcription' &&
            event.subject_type === 'audio_recording',
        urlForEvent: (event) =>
            AudioRecordingController.live.url({
                tenant: currentWorkspace,
                audioRecording: event.subject_id,
            }),
        onData: ({ audio_recording: next }) => {
            setRecordings((previous) => {
                const current = previous.find((item) => item.id === next.id);

                if (
                    current &&
                    current.transcription_status !== 'completed' &&
                    next.transcription_status === 'completed'
                ) {
                    toast.success(
                        t('Transcript ready for :file.', {
                            file: next.original_filename,
                        }),
                    );
                }

                return previous.map((item) =>
                    item.id === next.id ? next : item,
                );
            });
        },
    });

    if (recordings.length === 0) {
        return null;
    }

    return (
        <div className="divide-y rounded-md border">
            {recordings.map((recording) => (
                <div key={recording.id} className="px-4 py-3 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="font-medium">
                                <a
                                    className="hover:underline"
                                    href={
                                        showAudioRecording({
                                            tenant: currentWorkspace,
                                            audioRecording: recording.id,
                                        }).url
                                    }
                                >
                                    {recording.original_filename}
                                </a>
                            </p>
                            <p className="flex items-center gap-1.5 text-muted-foreground">
                                {(recording.transcription_status ===
                                    'pending' ||
                                    recording.transcription_status ===
                                        'processing') && (
                                    <Spinner className="size-3.5 shrink-0" />
                                )}
                                <span>
                                    {t(
                                        recording.transcription_status ===
                                            'completed'
                                            ? 'Transcribed'
                                            : recording.transcription_status ===
                                                'failed'
                                              ? 'Failed'
                                              : 'Transcribing…',
                                    )}{' '}
                                    ·{' '}
                                    {t('Uploaded :datetime', {
                                        datetime: formatDateTime(
                                            recording.uploaded_at,
                                        ),
                                    })}
                                </span>
                            </p>
                            {recording.transcription_error ? (
                                <p className="text-destructive">
                                    {recording.transcription_error}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex items-center gap-2">
                            {canDownload ? (
                                <a
                                    className={buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    })}
                                    href={AudioRecordingController.download.url(
                                        {
                                            tenant: currentWorkspace,
                                            audioRecording: recording.id,
                                        },
                                    )}
                                >
                                    <Download aria-hidden="true" />
                                    {t('Download')}
                                </a>
                            ) : null}
                            <LinkButton
                                variant="outline"
                                size="sm"
                                href={
                                    showAudioRecording({
                                        tenant: currentWorkspace,
                                        audioRecording: recording.id,
                                    }).url
                                }
                            >
                                {t('View transcript')}
                            </LinkButton>
                            {recording.can_delete ? (
                                <ConfirmDestroyDialog
                                    title={t('Remove :filename?', {
                                        filename: recording.original_filename,
                                    })}
                                    description={t(
                                        'The audio file and its transcript will be permanently removed.',
                                    )}
                                    confirmLabel={t('Remove recording')}
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label={t('Remove :filename', {
                                                filename:
                                                    recording.original_filename,
                                            })}
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    }
                                    form={AudioRecordingController.destroy.form(
                                        {
                                            tenant: currentWorkspace,
                                            audioRecording: recording.id,
                                        },
                                    )}
                                />
                            ) : null}
                        </div>
                    </div>
                    {recording.pipeline ? (
                        <div className="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                            {recording.pipeline.steps.map((step) => (
                                <span
                                    key={step.key}
                                    className="rounded-full border px-2 py-1"
                                >
                                    {pipelineStepLabel(step.key, t)}:{' '}
                                    {pipelineStepStatusLabel(step.status, t)}
                                </span>
                            ))}
                        </div>
                    ) : null}
                </div>
            ))}
        </div>
    );
}
