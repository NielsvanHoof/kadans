<?php

declare(strict_types=1);

namespace App\Actions\Dossiers;

use App\Actions\Audit\LogAuditActivityAction;
use App\Enums\AuditEvent;
use App\Enums\DossierExportStatus;
use App\Models\DossierExport;
use Throwable;

final class MarkDossierExportFailedAction
{
    public function __construct(private readonly LogAuditActivityAction $logAuditActivity) {}

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
