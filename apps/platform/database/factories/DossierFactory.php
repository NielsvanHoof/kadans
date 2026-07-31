<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<Dossier>
 */
final class DossierFactory extends Factory
{
    #[Override]
    protected $model = Dossier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => fake()->sentence(3),
            'reference' => mb_strtoupper(fake()->unique()->bothify('DOS-####')),
            'status' => DossierStatus::Draft,
            'responsible_user_id' => null,
            'due_date' => null,
            'reminder_interval_days' => null,
            'next_reminder_at' => null,
            'last_client_message_sent_at' => null,
            'last_client_opened_at' => null,
        ];
    }

    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (Dossier $dossier): void {
            if ($dossier->tenant_id !== null) {
                return;
            }

            $client = Client::queryWithoutTenantScope()
                ->find($dossier->client_id);

            if ($client instanceof Client) {
                $dossier->tenant_id = $client->tenant_id;
            }
        });
    }
}
