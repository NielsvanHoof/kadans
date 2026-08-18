<?php

declare(strict_types=1);

namespace App\AudioProcessing\Actions;

use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\Dossiers\Models\AudioRecording;

/**
 * Notify the staff member who uploaded a recording once its transcript is ready.
 * No-op when the upload has no known uploader (e.g. a future automated intake).
 */
final class NotifyUploaderOfTranscriptionCompletedAction
{
    public function handle(AudioRecording $audioRecording): void
    {
        $audioRecording->loadMissing(['uploadedBy', 'dossier', 'tenant']);

        $uploadedBy = $audioRecording->uploadedBy;

        if ($uploadedBy === null) {
            return;
        }

        $dossier = $audioRecording->dossier;

        $uploadedBy->notify(new AudioTranscriptionCompletedNotification(
            tenantId: $audioRecording->tenant_id,
            audioRecordingId: $audioRecording->id,
            originalFilename: $audioRecording->original_filename,
            dossierId: $audioRecording->dossier_id,
            dossierTitle: $dossier->title ?? '',
            audioRecordingUrl: route('workspaces.audio-recordings.show', [
                'tenant' => $audioRecording->tenant,
                'audioRecording' => $audioRecording,
            ]),
        ));
    }
}
