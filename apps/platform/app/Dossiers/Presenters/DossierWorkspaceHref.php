<?php

declare(strict_types=1);

namespace App\Dossiers\Presenters;

use App\Tenancy\Models\Tenant;

/**
 * Every pipeline subject lives under a dossier, so all three presenters point
 * at the same page. Shared here rather than repeated per domain.
 */
trait DossierWorkspaceHref
{
    private function dossierHref(?int $dossierId): ?string
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant || $dossierId === null) {
            return null;
        }

        return route('workspaces.dossiers.show', [
            'tenant' => $tenant->slug,
            'dossier' => $dossierId,
        ]);
    }
}
