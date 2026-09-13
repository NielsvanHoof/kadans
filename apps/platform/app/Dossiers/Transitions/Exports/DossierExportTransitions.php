<?php

declare(strict_types=1);

namespace App\Dossiers\Transitions\Exports;

use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Models\DossierExport;
use Carbon\CarbonInterface;

/**
 * Guarded status transitions for dossier exports.
 *
 * Flow: pending → processing → completed|failed. A failed or completed
 * export can be retried, which resets it to pending for another run.
 *
 * Writes are quiet (saveQuietly): DossierExport does not use LogsActivity,
 * so these writes were never part of the activity log to begin with.
 * Business-meaningful events (requested, completed, failed) are logged
 * explicitly via LogAuditActivityAction by the calling action instead.
 */
final class DossierExportTransitions
{
    public function canStart(DossierExport $export): bool
    {
        return $export->status !== DossierExportStatus::Completed;
    }

    public function start(DossierExport $export): DossierExport
    {
        $export->forceFill([
            'status' => DossierExportStatus::Processing,
            'error' => null,
            'failed_at' => null,
        ])->saveQuietly();

        return $export;
    }

    /**
     * `disk` and `path` are overwritten here on every successful run, so a
     * retried export always points at its latest archive.
     */
    public function complete(
        DossierExport $export,
        string $disk,
        string $path,
        CarbonInterface $completedAt,
        CarbonInterface $expiresAt,
    ): DossierExport {
        $export->forceFill([
            'status' => DossierExportStatus::Completed,
            'disk' => $disk,
            'path' => $path,
            'expires_at' => $expiresAt,
            'completed_at' => $completedAt,
        ])->saveQuietly();

        return $export;
    }

    public function fail(DossierExport $export, string $errorMessage): DossierExport
    {
        $export->forceFill([
            'status' => DossierExportStatus::Failed,
            'error' => mb_substr($errorMessage, 0, 500),
            'failed_at' => now(),
        ])->saveQuietly();

        return $export;
    }

    /**
     * `disk` and `path` are left alone: complete() overwrites them on
     * success, and isDownloadable() already fails while the export is not
     * completed.
     */
    public function retry(DossierExport $export): DossierExport
    {
        $export->forceFill([
            'status' => DossierExportStatus::Pending,
            'error' => null,
            'expires_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ])->saveQuietly();

        return $export;
    }
}
