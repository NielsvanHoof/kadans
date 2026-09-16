<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Access;

use App\Accounts\Models\User;
use App\ClientPortal\Enums\ClientAccessGrantScope;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateClientAccessGrantAction
{
    public function __construct(
        private ShareDossierWithClientAction $shareDossierWithClient,
    ) {}

    /**
     * Create a hashed grant for one dossier and return the one-time plain token.
     *
     * Issuing a link still shares the dossier, because that is what staff mean
     * by it — but the sharing itself now belongs to ShareDossierWithClientAction,
     * which also runs on its own for firms working from a client-level link.
     *
     * @return array{grant: ClientAccessGrant, plain_text_token: string}
     */
    public function handle(Dossier $dossier, User $createdBy, int $expiresInDays = 7): array
    {
        return DB::transaction(function () use ($dossier, $createdBy, $expiresInDays): array {
            $lockedDossier = $this->shareDossierWithClient->handle($dossier, $createdBy);

            $plainTextToken = Str::random(64);

            $grant = $lockedDossier->clientAccessGrants()->create([
                'scope' => ClientAccessGrantScope::Dossier,
                'client_id' => $lockedDossier->client_id,
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

    /**
     * Create a hashed grant covering everything the firm shares with a client.
     *
     * Deliberately shares nothing: a standing link is a door, not a decision
     * about which dossiers go behind it. Staff open those one at a time with
     * ShareDossierWithClientAction, and this link picks them up as they do.
     *
     * @return array{grant: ClientAccessGrant, plain_text_token: string}
     */
    public function handleForClient(Client $client, User $createdBy, int $expiresInDays): array
    {
        return DB::transaction(function () use ($client, $createdBy, $expiresInDays): array {
            $plainTextToken = Str::random(64);

            // Created straight off the model rather than through a relation
            // on Client: Clients (L4) may not reference ClientPortal (L5), and
            // BelongsToTenant fills tenant_id from the current tenant anyway.
            $grant = ClientAccessGrant::query()->create([
                'scope' => ClientAccessGrantScope::Client,
                'client_id' => $client->getKey(),
                'dossier_id' => null,
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
