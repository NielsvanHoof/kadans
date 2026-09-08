<?php

declare(strict_types=1);

namespace App\ClientPortal\Data\Workspace;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Firm branding snippet on the client portal.
 *
 * The contact is the staff member already corresponding with this client — the
 * dossier's responsible user, or whoever issued the access link. Without it the
 * portal told clients to "contact the firm" and gave them no way to.
 *
 * Never null: `client_access_grants.created_by` cascades on delete, so a grant
 * cannot outlive the staff member who issued it.
 */
#[TypeScript]
final class PortalFirmData extends Data
{
    public function __construct(
        public string $name,
        public readonly string $contactName,
        public readonly string $contactEmail,
    ) {}
}
