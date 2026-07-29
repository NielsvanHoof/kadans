<?php

declare(strict_types=1);

namespace Database\Factories;

use Override;
use App\Accounts\Models\User;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierExport>
 */
final class DossierExportFactory extends Factory
{
    #[Override]
    protected $model = DossierExport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_id' => Dossier::factory(),
            'requested_by' => User::factory(),
            'status' => DossierExportStatus::Pending,
            'disk' => null,
            'path' => null,
            'error' => null,
            'expires_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ];
    }

    public function completed(string $path = 'exports/dossier.zip'): static
    {
        return $this->state(fn (): array => [
            'status' => DossierExportStatus::Completed,
            'disk' => 'local',
            'path' => $path,
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
        ]);
    }

    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (DossierExport $export): void {
            if ($export->tenant_id !== null) {
                return;
            }

            $dossier = Dossier::query()
                ->withoutGlobalScopes()
                ->find($export->dossier_id);

            if ($dossier instanceof Dossier) {
                $export->tenant_id = $dossier->tenant_id;
            }
        });
    }
}
