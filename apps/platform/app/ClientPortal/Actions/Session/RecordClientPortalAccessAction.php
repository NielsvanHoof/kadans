<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Session;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\Access\FetchReachableDossiersQuery;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\DB;

/**
 * Record that a client opened the portal.
 * Touches the grant, stamps the dossier they opened, and writes an audit event.
 */
final readonly class RecordClientPortalAccessAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private FetchReachableDossiersQuery $fetchReachableDossiers,
    ) {}

    /**
     * $dossier is the one the client actually landed on. Null on the landing
     * list, where they have opened the link but not yet chosen a dossier — then
     * there is nothing to stamp, because no dossier has been looked at.
     */
    public function handle(ClientAccessGrant $grant, ?Dossier $dossier = null): void
    {
        DB::transaction(function () use ($grant, $dossier): void {
            $openedAt = now();
            $grant->forceFill(['last_used_at' => $openedAt])->save();

            if ($dossier instanceof Dossier) {
                // Keep dossier follow-up timestamps in sync with portal opens.
                Dossier::query()
                    ->whereKey($dossier->getKey())
                    ->toBase()
                    ->update(['last_client_opened_at' => $openedAt]);
            }

            $this->logAuditActivity->handle(
                AuditEvent::ClientPortalAccessed,
                $grant,
                [
                    'source' => 'client_portal',
                    'client_id' => $grant->client_id,
                    'dossier_id' => $dossier?->getKey(),
                    // How much this link opened at the moment it was used. A
                    // client-scoped link grows as dossiers are shared, so the
                    // trail has to say what its reach was at the time, not what
                    // it happens to be when someone reads the entry later.
                    'reachable_dossier_count' => $this->fetchReachableDossiers->handle($grant)->count(),
                ],
            );
        });
    }
}
