<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Models\DossierExport;
use Throwable;

final readonly class MarkDossierExportFailedAction
{
    public function __construct(private LogAuditActivityAction $logAuditActivity) {}

    public function handle(DossierExport $export, Throwable $exception): void
    {
        $export->forceFill([
            'status' => DossierExportStatus::Failed,
            'error' => mb_substr($exception->getMessage(), 0, 500),
            'failed_at' => now(),
        ])->saveQuietly();

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
