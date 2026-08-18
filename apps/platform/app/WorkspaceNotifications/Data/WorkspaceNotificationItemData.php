<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One staff inbox notification row.
 */
#[TypeScript]
final class WorkspaceNotificationItemData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $message,
        public readonly string $url,
        public readonly ?string $readAt,
        public readonly string $createdAt,
        // Present for 'client_questionnaire_completed' rows.
        public readonly ?int $dossierId = null,
        public readonly ?string $dossierTitle = null,
        public readonly ?string $clientName = null,
        // Present for 'audio_transcription_completed' rows.
        public readonly ?int $audioRecordingId = null,
        public readonly ?string $originalFilename = null,
    ) {}
}
