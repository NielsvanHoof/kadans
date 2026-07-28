<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequest>
 */
final class DocumentRequestFactory extends Factory
{
    protected $model = DocumentRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_id' => Dossier::factory(),
            'type' => QuestionnaireItemType::File,
            'title' => fake()->sentence(2),
            'instructions' => fake()->optional()->sentence(),
            'status' => DocumentRequestStatus::Pending,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (DocumentRequest $documentRequest): void {
            if ($documentRequest->tenant_id !== null) {
                return;
            }

            $dossier = Dossier::query()
                ->withoutGlobalScopes()
                ->find($documentRequest->dossier_id);

            if ($dossier instanceof Dossier) {
                $documentRequest->tenant_id = $dossier->tenant_id;
            }
        });
    }
}
