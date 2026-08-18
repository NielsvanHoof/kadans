<?php

declare(strict_types=1);

namespace App\Pipelines\Models;

use App\Accounts\Models\User;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineType;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;

/**
 * A tenant-scoped execution record for a long-running application process.
 *
 * created_at is nullable in DDL but Eloquent always writes it; metadata's
 * element types are beyond what the `array` cast can express.
 *
 * @property int $id
 * @property int $tenant_id
 * @property PipelineType $type
 * @property PipelineStatus $status
 * @property PipelineStepKey|null $current_stage
 * @property string|null $provider
 * @property string|null $provider_job_id
 * @property string $pipelineable_type
 * @property int $pipelineable_id
 * @property int|null $initiated_by
 * @property int $attempt_number
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<array-key, mixed>|null $metadata
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $next_retry_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $initiatedBy
 * @property-read Model $pipelineable
 * @property-read Collection<int, PipelineStep> $steps
 * @property-read int|null $steps_count
 * @property-read Tenant $tenant
 *
 * @method static PipelineFactory factory($count = null, $state = [])
 * @method static Builder<static>|Pipeline newModelQuery()
 * @method static Builder<static>|Pipeline newQuery()
 * @method static Builder<static>|Pipeline query()
 * @method static Builder<static>|Pipeline whereAttemptNumber($value)
 * @method static Builder<static>|Pipeline whereCompletedAt($value)
 * @method static Builder<static>|Pipeline whereCreatedAt($value)
 * @method static Builder<static>|Pipeline whereCurrentStage($value)
 * @method static Builder<static>|Pipeline whereErrorCode($value)
 * @method static Builder<static>|Pipeline whereErrorMessage($value)
 * @method static Builder<static>|Pipeline whereFailedAt($value)
 * @method static Builder<static>|Pipeline whereId($value)
 * @method static Builder<static>|Pipeline whereInitiatedBy($value)
 * @method static Builder<static>|Pipeline whereLastActivityAt($value)
 * @method static Builder<static>|Pipeline whereMetadata($value)
 * @method static Builder<static>|Pipeline whereNextRetryAt($value)
 * @method static Builder<static>|Pipeline wherePipelineableId($value)
 * @method static Builder<static>|Pipeline wherePipelineableType($value)
 * @method static Builder<static>|Pipeline whereProvider($value)
 * @method static Builder<static>|Pipeline whereProviderJobId($value)
 * @method static Builder<static>|Pipeline whereStartedAt($value)
 * @method static Builder<static>|Pipeline whereStatus($value)
 * @method static Builder<static>|Pipeline whereTenantId($value)
 * @method static Builder<static>|Pipeline whereType($value)
 * @method static Builder<static>|Pipeline whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class Pipeline extends TenantOwnedModel
{
    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'type',
        'status',
        'current_stage',
        'provider',
        'provider_job_id',
        'pipelineable_type',
        'pipelineable_id',
        'initiated_by',
        'attempt_number',
        'error_code',
        'error_message',
        'metadata',
        'started_at',
        'last_activity_at',
        'next_retry_at',
        'completed_at',
        'failed_at',
    ];

    #[Override]
    protected $attributes = [
        'status' => 'pending',
        'attempt_number' => 1,
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function pipelineable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PipelineStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(PipelineStep::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => PipelineType::class,
            'status' => PipelineStatus::class,
            'current_stage' => PipelineStepKey::class,
            'attempt_number' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
