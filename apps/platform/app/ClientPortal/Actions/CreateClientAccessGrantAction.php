<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions;

use App\Accounts\Models\User;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Transitions\DossierTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateClientAccessGrantAction
{
    public function __construct(
        private readonly DossierTransitions $dossierTransitions,
    ) {}

    /**
     * Create a hashed client portal grant and return the one-time plain token.
     *
     * @return array{grant: ClientAccessGrant, plain_text_token: string}
     */
    public function handle(Dossier $dossier, User $createdBy, int $expiresInDays = 7): array
    {
        return DB::transaction(function () use ($dossier, $createdBy, $expiresInDays): array {
            $dossierQuery = Dossier::query()->whereKey($dossier->getKey());
            $dossierQuery->getQuery()->lockForUpdate();
            $lockedDossier = $dossierQuery->firstOrFail();

            $this->dossierTransitions->markAwaitingClient($lockedDossier);

            $plainTextToken = Str::random(64);

            $grant = $lockedDossier->clientAccessGrants()->create([
                'token' => ClientAccessGrant::hashToken($plainTextToken),
                'expires_at' => now()->addDays($expiresInDays),
                'created_by' => $createdBy->id,
            ]);

            return [
                'grant' => $grant,
                'plain_text_token' => $plainTextToken,
            ];
        });
    }
}
