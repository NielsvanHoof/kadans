<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Exports;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Transitions\Exports\DossierExportTransitions;
use Throwable;

final readonly class MarkDossierExportFailedAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private DossierExportTransitions $transitions,
    ) {}

    public function handle(DossierExport $export, Throwable $exception): void
    {
        $this->transitions->fail($export, $exception->getMessage());

        $this->logAuditActivity->handle(
            AuditEvent::DossierExportFailed,
            $export,
            [
                'dossier_id' => $export->dossier_id,
                'error' => $export->error,
            ],
            includeRequestContext: false,
        );
    }
}
