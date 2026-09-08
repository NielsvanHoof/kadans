<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Client-side stalls the firm can act on: invitations that never landed,
 * conversations that went quiet, and portal links about to expire.
 *
 * Deferred rather than cached — ClientAccessGrant does not use
 * InvalidatesTenantCaches, so a cached copy would not be evicted when a client
 * finally opens their link.
 */
#[TypeScript]
final class DashboardFollowUpSignalsData extends Data
{
    /**
     * @param  list<DashboardFollowUpItemData>  $neverOpened
     * @param  list<DashboardFollowUpItemData>  $goneQuiet
     * @param  list<DashboardFollowUpItemData>  $expiringLinks
     */
    public function __construct(
        /** Invited, but the portal link has never been opened. */
        public readonly array $neverOpened,
        /** Opened once, then no client activity for longer than the reminder interval. */
        public readonly array $goneQuiet,
        /** Live access grants expiring within the week. */
        public readonly array $expiringLinks,
        public readonly int $totalCount,
    ) {}
}
