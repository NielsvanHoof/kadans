<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Access;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Models\ClientAccessGrant;
use Illuminate\Support\Facades\DB;

final readonly class RevokeClientPortalAccessAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(ClientAccessGrant $grant, User $revokedBy): ClientAccessGrant
    {
        return DB::transaction(function () use ($grant, $revokedBy): ClientAccessGrant {
            $lockedGrant = $grant->refreshForUpdate();

            if ($lockedGrant->revoked_at !== null) {
                return $lockedGrant;
            }

            $lockedGrant->update(['revoked_at' => now()]);

            $this->logAuditActivity->handle(
                AuditEvent::ClientPortalAccessGrantRevoked,
                $lockedGrant,
                ['dossier_id' => $lockedGrant->dossier_id],
                $revokedBy,
            );

            return $lockedGrant;
        });
    }
}
