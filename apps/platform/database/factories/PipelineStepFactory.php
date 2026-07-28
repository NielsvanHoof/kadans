<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DocumentProcessing\Enums\PipelineStepStatus;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStep>
 */
final class PipelineStepFactory extends Factory
{
    protected $model = PipelineStep::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'key' => 'process',
            'position' => 0,
            'status' => PipelineStepStatus::Pending,
            'attempts' => 0,
            'error_code' => null,
            'error_message' => null,
            'metadata' => null,
            'started_at' => null,
            'last_activity_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ];
    }
}
