<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Accounts\Models\User;
use App\ClientPortal\Enums\ClientAccessGrantScope;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Override;

/**
 * @extends Factory<ClientAccessGrant>
 */
final class ClientAccessGrantFactory extends Factory
{
    #[Override]
    protected $model = ClientAccessGrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => ClientAccessGrantScope::Dossier,
            'dossier_id' => Dossier::factory(),
            'token' => ClientAccessGrant::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(7),
            'created_by' => User::factory(),
        ];
    }

    /**
     * One link covering everything the firm has shared with the client.
     */
    public function clientScoped(?Client $client = null): static
    {
        return $this->state(fn (): array => [
            'scope' => ClientAccessGrantScope::Client,
            'dossier_id' => null,
            'client_id' => $client?->getKey() ?? Client::factory(),
        ]);
    }

    /**
     * Fills in whatever the caller did not pin.
     *
     * Both tenant and client are derivable from the dossier for a dossier-scoped
     * grant, and tests overwhelmingly pass only the dossier. A client-scoped
     * grant has no dossier to read, so its tenant comes from the client instead.
     */
    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (ClientAccessGrant $grant): void {
            if ($grant->dossier_id !== null && $grant->client_id === null) {
                $dossier = Dossier::queryWithoutTenantScope()->find($grant->dossier_id);

                if ($dossier instanceof Dossier) {
                    $grant->client_id = $dossier->client_id;
                }
            }

            if ($grant->tenant_id !== null) {
                return;
            }

            $tenantId = match (true) {
                $grant->dossier_id !== null => Dossier::queryWithoutTenantScope()
                    ->find($grant->dossier_id)?->tenant_id,
                $grant->client_id !== null => Client::queryWithoutTenantScope()
                    ->find($grant->client_id)?->tenant_id,
                default => null,
            };

            if ($tenantId !== null) {
                $grant->tenant_id = $tenantId;
            }
        });
    }
}
