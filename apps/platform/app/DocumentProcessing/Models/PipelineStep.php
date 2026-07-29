<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Models;

use App\DocumentProcessing\Enums\PipelineStepStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\PipelineStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * Operational stage within a pipeline execution.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $pipeline_id
 * @property string $key
 * @property int $position
 * @property PipelineStepStatus $status
 * @property int $attempts
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $started_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 */
#[Fillable([
    'tenant_id',
    'pipeline_id',
    'key',
    'position',
    'status',
    'attempts',
    'error_code',
    'error_message',
    'metadata',
    'started_at',
    'last_activity_at',
    'completed_at',
    'failed_at',
])]
#[UseFactory(PipelineStepFactory::class)]
final class PipelineStep extends Model
{
    /** @use HasFactory<PipelineStepFactory> */
    use BelongsToTenant, HasFactory;

    #[Override]
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => PipelineStepStatus::class,
            'position' => 'integer',
            'attempts' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
