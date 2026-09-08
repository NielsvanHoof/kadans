<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Session;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\DB;

/**
 * Record that a client opened the portal.
 * Touches the grant, stamps the dossier, and writes an audit event.
 */
final readonly class RecordClientPortalAccessAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(ClientAccessGrant $grant): void
    {
        DB::transaction(function () use ($grant): void {
            $openedAt = now();
            $grant->forceFill(['last_used_at' => $openedAt])->save();

            // Keep dossier follow-up timestamps in sync with portal opens.
            Dossier::query()
                ->whereKey($grant->dossier_id)
                ->toBase()
                ->update(['last_client_opened_at' => $openedAt]);

            $this->logAuditActivity->handle(
                AuditEvent::ClientPortalAccessed,
                $grant,
                [
                    'source' => 'client_portal',
                    'dossier_id' => $grant->dossier_id,
                ],
            );
        });
    }
}
