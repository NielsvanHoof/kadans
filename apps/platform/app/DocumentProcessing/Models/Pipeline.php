<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Models;

use App\Accounts\Models\User;
use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * A tenant-scoped execution record for a long-running application process.
 *
 * @property int $id
 * @property int $tenant_id
 * @property PipelineType $type
 * @property PipelineStatus $status
 * @property string|null $current_stage
 * @property string|null $provider
 * @property string|null $provider_job_id
 * @property string $pipelineable_type
 * @property int $pipelineable_id
 * @property int|null $initiated_by
 * @property int $attempt_number
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $started_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
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
])]
#[UseFactory(PipelineFactory::class)]
final class Pipeline extends Model
{
    /** @use HasFactory<PipelineFactory> */
    use BelongsToTenant, HasFactory;

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
