<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PipelineStatus;
use App\Enums\PipelineType;
use App\Models\Pipeline;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
final class PipelineFactory extends Factory
{
    protected $model = Pipeline::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => PipelineType::OcrDocument,
            'status' => PipelineStatus::Pending,
            'current_stage' => null,
            'provider' => null,
            'provider_job_id' => null,
            'pipelineable_type' => null,
            'pipelineable_id' => null,
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

    public function dossierExport(): static
    {
        return $this->state(['type' => PipelineType::DossierExport]);
    }
}
