<?php

declare(strict_types=1);

namespace App\AudioProcessing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Persist a workspace inbox item for the staff member who uploaded a recording,
 * once its transcript is ready. Real-time push reuses the existing
 * pipeline.updated broadcast (App\DocumentProcessing\Events\PipelineUpdated);
 * this is the DB source of truth for the bell.
 */
final class AudioTranscriptionCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const string DatabaseType = 'audio_transcription_completed';

    public function __construct(
        public readonly int $tenantId,
        public readonly int $audioRecordingId,
        public readonly string $originalFilename,
        public readonly int $dossierId,
        public readonly string $dossierTitle,
        public readonly string $audioRecordingUrl,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Telescope tags for the queued notification job.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'notification',
            self::DatabaseType,
            'tenant:'.$this->tenantId,
            'audio_recording:'.$this->audioRecordingId,
        ];
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
