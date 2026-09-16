<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Access;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierLocks;
use App\Dossiers\Transitions\Lifecycle\DossierTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Put a dossier in front of the client.
 *
 * This is the only thing that moves a dossier out of Draft, and it is separate
 * from minting a link on purpose: a client-scoped grant is a standing door, so
 * "the client may see this dossier" and "here is a token" stopped being the
 * same event. CreateClientAccessGrantAction calls this rather than reaching for
 * DossierTransitions itself, which keeps markAwaitingClient down to one caller.
 *
 * Lives in ClientPortal rather than Dossiers because sharing is authorised by
 * ClientAccessGrantPermission, and Dossiers (L4) cannot import ClientPortal (L5).
 */
final readonly class ShareDossierWithClientAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
        private DossierTransitions $dossierTransitions,
    ) {}

    /**
     * Returns the locked dossier so a caller already inside a transaction —
     * CreateClientAccessGrantAction — can go on using it without re-locking.
     */
    public function handle(Dossier $dossier, User $sharedBy): Dossier
    {
        return DB::transaction(function () use ($dossier, $sharedBy): Dossier {
            $lockedDossier = $this->dossierLocks->dossier($dossier);

            // Read before the transition, which is a no-op for a dossier that
            // is already past Draft. Auditing unconditionally would fill the
            // trail with "shared" entries for dossiers shared months ago.
            $wasDraft = $lockedDossier->status === DossierStatus::Draft;

            $this->dossierTransitions->markAwaitingClient($lockedDossier);

            if ($wasDraft) {
                $this->logAuditActivity->handle(
                    AuditEvent::DossierSharedWithClient,
                    $lockedDossier,
                    [
                        'client_id' => $lockedDossier->client_id,
                        // Which standing links can reach it from now on. This is
                        // the record that answers "what could see this, and when".
                        'client_access_grant_ids' => $this->coveringClientGrantIds($lockedDossier),
                    ],
                    $sharedBy,
                );
            }

            return $lockedDossier;
        });
    }

    /**
     * @return list<int>
     */
    private function coveringClientGrantIds(Dossier $dossier): array
    {
        $query = ClientAccessGrant::query();
        $query->getQuery()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereNull('dossier_id')
            ->where('client_id', $dossier->client_id);

        $ids = [];

        foreach ($query->toBase()->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
