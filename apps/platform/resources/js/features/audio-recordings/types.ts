export type TranscriptionStatus =
    App.DocumentProcessing.Enums.TranscriptionStatus;

/** Uploaded audio on a staff dossier (list/live payload — no transcript body). */
export type AudioRecording = App.Dossiers.Data.StaffAudioRecordingData;

/** Dedicated transcript page props (staff). */
export type AudioRecordingShowProps = {
    audio_recording: AudioRecording & {
        transcript: string | null;
    };
    dossier: { id: number; title: string } | null;
    can_download: boolean;
};
