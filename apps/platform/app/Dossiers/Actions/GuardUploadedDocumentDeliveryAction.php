<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Models\UploadedDocument;

/**
 * Refuse anything GuardDuty has not established as clean.
 *
 * Fails closed on an unscanned file too. Scans are asynchronous, so a freshly
 * uploaded document has no verdict yet — and at the point of delivery "not yet
 * scanned" and "infected" are the same fact. The refusal is audited because
 * someone being told no is exactly the sort of thing an operator needs to see
 * without being asked.
 *
 * Returns a verdict rather than aborting: the two callers conceal a refusal
 * differently. Staff get a 403 with an explanation; the portal gets a 404,
 * because portal denials are never 403 (see the ClientPortal rules).
 */
final readonly class GuardUploadedDocumentDeliveryAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private ResolveMalwareScanStatusAction $resolveMalwareScanStatus,
    ) {}

    /**
     * Whether these bytes may be delivered. Audits the refusal when they may not.
     */
    public function handle(UploadedDocument $uploadedDocument): bool
    {
        $verdict = $this->resolveMalwareScanStatus->handle(
            $uploadedDocument->disk,
            $uploadedDocument->path,
        );

        if ($verdict->permitsDelivery) {
            return true;
        }

        $this->logAuditActivity->handle(
            AuditEvent::FileBlockedByMalwareScan,
            $uploadedDocument,
            [
                'original_filename' => $uploadedDocument->original_filename,
                // Null reads as "no verdict yet", which is the common case and a
                // materially different situation from a detection.
                'scan_status' => $verdict->status?->value,
            ],
        );

        return false;
    }
}
