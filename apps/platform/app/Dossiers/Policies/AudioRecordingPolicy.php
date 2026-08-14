<?php

declare(strict_types=1);

namespace App\Dossiers\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Enums\AudioRecordingPermission;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class AudioRecordingPolicy
{
    use AuthorizesTenantPermission;

    /**
     * Uploading an audio recording onto a dossier.
     */
    public function create(User $user, Dossier $dossier): bool
    {
        return $this->canActOnOpenDossier($user, $dossier, AudioRecordingPermission::Upload);
    }

    /**
     * Opening a recording's transcript.
     */
    public function view(User $user, AudioRecording $audioRecording): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $audioRecording->tenant,
            AudioRecordingPermission::View,
        );
    }

    public function download(User $user, AudioRecording $audioRecording): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $audioRecording->tenant,
            AudioRecordingPermission::Download,
        );
    }

    public function delete(User $user, AudioRecording $audioRecording): bool
    {
        return $this->canActOnOpenDossier($user, $audioRecording->dossier, AudioRecordingPermission::Delete);
    }

    private function canActOnOpenDossier(
        User $user,
        ?Dossier $dossier,
        TenantPermission $permission,
    ): bool {
        return $dossier instanceof Dossier
            && $this->userHasPermissionInTenant($user, $dossier->tenant, $permission)
            && ! $dossier->status->isLocked();
    }
}
