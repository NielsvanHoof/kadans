<?php

declare(strict_types=1);

namespace App\AudioProcessing\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Persist a workspace inbox item for the staff member who uploaded a recording,
 * once its transcript is ready. Real-time push stays on AudioTranscriptionCompleted
 * (Reverb); this is the DB source of truth for the bell.
 *
 * Deliberately not ShouldQueue, for the same reason as
 * ClientQuestionnaireCompletedNotification: a queued row races the broadcast
 * that tells the bell to go and read it. TASK-64.
 */
final class AudioTranscriptionCompletedNotification extends Notification
{
    public const string DatabaseType = 'audio_transcription_completed';

    public function __construct(
        public readonly int $tenantId,
        public readonly int $audioRecordingId,
        public readonly string $originalFilename,
        public readonly int $dossierId,
        public readonly string $dossierTitle,
        public readonly string $audioRecordingUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return self::DatabaseType;
    }

    /**
     * @return array{
     *     tenant_id: string,
     *     type: string,
     *     audio_recording_id: int,
     *     original_filename: string,
     *     dossier_id: int,
     *     dossier_title: string,
     *     audio_recording_url: string
     * }
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => (string) $this->tenantId,
            'type' => self::DatabaseType,
            'audio_recording_id' => $this->audioRecordingId,
            'original_filename' => $this->originalFilename,
            'dossier_id' => $this->dossierId,
            'dossier_title' => $this->dossierTitle,
            'audio_recording_url' => $this->audioRecordingUrl,
        ];
    }
}
