<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One dossier needing a nudge, with the timestamp that earned it the flag.
 */
#[TypeScript]
final class DashboardFollowUpItemData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $clientName,
        /** The instant this row is measured from — invited at, last opened, or expiring at. */
        public readonly ?string $since,
        /** Whole days between `since` and today, for a "9 days" style label. */
        public readonly ?int $days,
    ) {}
}
