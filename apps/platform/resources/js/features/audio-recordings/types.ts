export type TranscriptionStatus =
    App.DocumentProcessing.Enums.TranscriptionStatus;

/** A single diarized utterance from the transcription provider. */
export type TranscriptSegment = {
    text: string;
    speaker: string;
    start_seconds: number;
    end_seconds: number;
};

/** Uploaded audio on a staff dossier (list/live payload — no transcript body). */
export type AudioRecording = App.Dossiers.Data.StaffAudioRecordingData;

/** Dedicated transcript page props (staff). */
export type AudioRecordingShowProps = {
    audio_recording: AudioRecording & {
        transcript: string | null;
        segments: TranscriptSegment[] | null;
    };
    dossier: { id: number; title: string } | null;
    can_download: boolean;
};
