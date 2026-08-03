<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Accounts\Models\User;
use App\ClientPortal\Models\ClientAccessGrant;
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
            'dossier_id' => Dossier::factory(),
            'token' => ClientAccessGrant::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(7),
            'created_by' => User::factory(),
        ];
    }

    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (ClientAccessGrant $grant): void {
            if ($grant->tenant_id !== null) {
                return;
            }

            $dossier = Dossier::queryWithoutTenantScope()
                ->find($grant->dossier_id);

            if ($dossier instanceof Dossier) {
                $grant->tenant_id = $dossier->tenant_id;
            }
        });
    }
}
