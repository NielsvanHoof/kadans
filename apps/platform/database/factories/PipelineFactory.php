<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Override;

/**
 * @extends Factory<Pipeline>
 */
final class PipelineFactory extends Factory
{
    #[Override]
    protected $model = Pipeline::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => PipelineType::OcrDocument,
            'status' => PipelineStatus::Pending,
            'current_stage' => null,
            'provider' => null,
            'provider_job_id' => null,
            'pipelineable_type' => (new UploadedDocument)->getMorphClass(),
            'pipelineable_id' => UploadedDocument::factory(),
            'initiated_by' => null,
            'attempt_number' => 1,
            'error_code' => null,
            'error_message' => null,
            'metadata' => null,
            'started_at' => null,
            'last_activity_at' => null,
            'next_retry_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ];
    }

    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (Pipeline $pipeline): void {
            if ($pipeline->tenant_id !== null) {
                return;
            }

            $modelClass = Relation::getMorphedModel($pipeline->pipelineable_type);

            if ($modelClass === null || ! is_a($modelClass, Model::class, true)) {
                return;
            }

            $subject = (new $modelClass)
                ->newQueryWithoutScopes()
                ->find($pipeline->pipelineable_id);
            $tenantId = $subject?->getAttribute('tenant_id');

            if (is_numeric($tenantId)) {
                $pipeline->tenant_id = (int) $tenantId;
            }
        });
    }

    public function dossierExport(): static
    {
        return $this->state(['type' => PipelineType::DossierExport]);
    }

    /**
     * A run that exhausted its queue attempts, with the diagnostic columns a
     * real failure leaves behind.
     */
    public function failed(): static
    {
        return $this->state([
            'status' => PipelineStatus::Failed,
            'current_stage' => 'submit',
            'provider' => 'textract',
            'provider_job_id' => 'textract-job-1',
            'error_code' => 'ocr_job_failed',
            'error_message' => 'The processing job failed.',
            'started_at' => now()->subMinutes(10),
            'last_activity_at' => now()->subMinutes(5),
            'failed_at' => now()->subMinutes(5),
        ]);
    }
}
