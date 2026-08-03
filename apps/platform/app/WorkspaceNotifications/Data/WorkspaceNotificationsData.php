<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Shared Inertia notifications payload for the staff bell.
 */
#[TypeScript]
final class WorkspaceNotificationsData extends Data
{
    /**
     * @param  list<WorkspaceNotificationItemData>  $items
     */
    public function __construct(
        public readonly int $unreadCount,
        public readonly array $items,
    ) {}
}
