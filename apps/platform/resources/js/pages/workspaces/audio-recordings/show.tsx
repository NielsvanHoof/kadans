import { Head, setLayoutProps } from '@inertiajs/react';
import AudioRecordingController from '@/actions/App/Dossiers/Http/Controllers/AudioRecordingController';
import AudioRecordingShowContent from '@/features/audio-recordings/audio-recording-show-content';
import type { AudioRecordingShowProps } from '@/features/audio-recordings/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    index as dossierIndex,
    show as showDossier,
} from '@/routes/workspaces/dossiers';

export default function ShowAudioRecording(props: AudioRecordingShowProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { audio_recording: audioRecording, dossier } = props;

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Dossiers'),
                href: dossierIndex(currentWorkspace),
            },
            ...(dossier
                ? [
                      {
                          title: dossier.title,
                          href: showDossier({
                              tenant: currentWorkspace,
                              dossier: dossier.id,
                          }),
                      },
                  ]
                : []),
            {
                title: audioRecording.original_filename,
                href: AudioRecordingController.show.url({
                    tenant: currentWorkspace,
                    audioRecording: audioRecording.id,
                }),
            },
        ],
    });

    return (
        <>
            <Head
                title={`${t('Transcript')} · ${audioRecording.original_filename}`}
            />
            <AudioRecordingShowContent {...props} />
        </>
    );
}
