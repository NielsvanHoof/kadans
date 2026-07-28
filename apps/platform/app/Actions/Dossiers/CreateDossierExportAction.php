<?php

declare(strict_types=1);

namespace App\Actions\Dossiers;

use App\Actions\Audit\LogAuditActivityAction;
use App\Enums\AuditEvent;
use App\Models\Dossier;
use App\Models\DossierExport;
use App\Models\User;

final class CreateDossierExportAction
{
    public function __construct(private readonly LogAuditActivityAction $logAuditActivity) {}

    public function handle(Dossier $dossier, User $requestedBy): DossierExport
    {
        $export = $dossier->exports()->create([
            'requested_by' => $requestedBy->id,
        ]);

        $this->logAuditActivity->handle(
            AuditEvent::DossierExportRequested,
            $export,
            [
                'dossier_id' => $dossier->id,
                'title' => $dossier->title,
            ],
            $requestedBy,
        );

        return $export;
    }
}
