<?php

declare(strict_types=1);

namespace App\AudioProcessing\Actions;

use App\AudioProcessing\Events\AudioTranscriptionCompleted;
use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\Dossiers\Models\AudioRecording;
use App\Tenancy\Models\Tenant;

/**
 * Notify the staff member who uploaded a recording once its transcript is ready.
 * No-op when the upload has no known uploader (e.g. a future automated intake).
 *
 * Also announces the completion to the workspace so the notification bell can
 * refresh. The broadcast is deliberately outside the uploader check below: the
 * bell shows a firm-wide inbox, and whether this particular recording has a
 * known uploader says nothing about whether staff should see the badge move.
 */
final class NotifyUploaderOfTranscriptionCompletedAction
{
    public function handle(AudioRecording $audioRecording): void
    {
        $audioRecording->loadMissing(['uploadedBy', 'dossier', 'tenant']);

        $tenant = $audioRecording->tenant;

        if ($tenant instanceof Tenant) {
            event(new AudioTranscriptionCompleted(
                tenantSlug: $tenant->slug,
                audioRecordingId: $audioRecording->id,
                dossierId: $audioRecording->dossier_id,
            ));
        }

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
