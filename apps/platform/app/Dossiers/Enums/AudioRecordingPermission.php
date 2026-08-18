<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum AudioRecordingPermission: string implements TenantPermission
{
    case View = 'audio_recordings.view';
    case Upload = 'audio_recordings.upload';
    case Download = 'audio_recordings.download';
    case Delete = 'audio_recordings.delete';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View audio recordings'),
            self::Upload => __('Upload audio recordings'),
            self::Download => __('Download audio recordings'),
            self::Delete => __('Remove audio recordings'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('Open an audio recording and its transcript in the browser.'),
            self::Upload => __('Attach an audio file to a dossier for transcription.'),
            self::Download => __('Save the original audio file to a local device.'),
            self::Delete => __('Remove an uploaded audio recording.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::AudioRecordings;
    }

    public function defaultRoles(): array
    {
        return match ($this) {
            self::View => [Role::Administrator, Role::Adviser, Role::Reviewer, Role::ReadOnly],
            self::Upload,
            self::Download,
            self::Delete => [Role::Administrator, Role::Adviser],
        };
    }
}
