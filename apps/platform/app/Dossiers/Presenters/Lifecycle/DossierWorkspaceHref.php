<?php

declare(strict_types=1);

namespace App\Dossiers\Presenters\Lifecycle;

use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;

/**
 * Every pipeline subject lives under a dossier, so all three presenters point
 * at the same page. Shared here rather than repeated per domain.
 */
trait DossierWorkspaceHref
{
    private function dossierContext(?Dossier $dossier): ?string
    {
        if (! $dossier instanceof Dossier) {
            return null;
        }

        $clientName = $dossier->client?->name;

        return $clientName === null || $clientName === ''
            ? $dossier->title
            : $clientName.' · '.$dossier->title;
    }

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
