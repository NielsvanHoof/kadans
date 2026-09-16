<?php

declare(strict_types=1);

namespace App\ClientPortal\Enums;

/**
 * How much of a firm's work one magic link opens.
 *
 * Kept as its own column even though it is derivable from a null `dossier_id`:
 * the reachability chokepoint branches on an exhaustive `match` over this enum,
 * so adding a third scope breaks the build instead of falling through to the
 * wider branch. A null check would do neither.
 */
enum ClientAccessGrantScope: string
{
    /** One dossier, whatever its status. Staff issued the link for that case. */
    case Dossier = 'dossier';

    /** Every dossier the firm has shared with the client, evaluated per request. */
    case Client = 'client';
}
