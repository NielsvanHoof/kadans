<?php

declare(strict_types=1);

namespace App\Pipelines\Models;

use App\Pipelines\Enums\PipelineStepStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\PipelineStepFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property array<array-key, mixed>|null $metadata
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Pipeline $pipeline
 * @property-read Tenant $tenant
 *
 * @method static PipelineStepFactory factory($count = null, $state = [])
 * @method static Builder<static>|PipelineStep newModelQuery()
 * @method static Builder<static>|PipelineStep newQuery()
 * @method static Builder<static>|PipelineStep query()
 * @method static Builder<static>|PipelineStep whereAttempts($value)
 * @method static Builder<static>|PipelineStep whereCompletedAt($value)
 * @method static Builder<static>|PipelineStep whereCreatedAt($value)
 * @method static Builder<static>|PipelineStep whereErrorCode($value)
 * @method static Builder<static>|PipelineStep whereErrorMessage($value)
 * @method static Builder<static>|PipelineStep whereFailedAt($value)
 * @method static Builder<static>|PipelineStep whereId($value)
 * @method static Builder<static>|PipelineStep whereKey($value)
 * @method static Builder<static>|PipelineStep whereLastActivityAt($value)
 * @method static Builder<static>|PipelineStep whereMetadata($value)
 * @method static Builder<static>|PipelineStep wherePipelineId($value)
 * @method static Builder<static>|PipelineStep wherePosition($value)
 * @method static Builder<static>|PipelineStep whereStartedAt($value)
 * @method static Builder<static>|PipelineStep whereStatus($value)
 * @method static Builder<static>|PipelineStep whereTenantId($value)
 * @method static Builder<static>|PipelineStep whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class PipelineStep extends TenantOwnedModel
{
    /** @use HasFactory<PipelineStepFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
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
    ];

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
