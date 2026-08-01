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
        public readonly int $dossierId,
        public readonly string $dossierTitle,
        public readonly string $clientName,
        public readonly string $dossierUrl,
        public readonly ?string $readAt,
        public readonly string $createdAt,
    ) {}
}
