<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\UploadedDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Override;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $document_request_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property CarbonImmutable $uploaded_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property DocumentProcessingStatus $processing_status
 * @property string|null $extracted_text
 * @property string|null $processing_error
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $processing_finished_at
 * @property string|null $textract_job_id
 * @property-read DocumentRequest $documentRequest
 * @property-read Pipeline|null $latestPipeline
 * @property-read Collection<int, Pipeline> $pipelines
 * @property-read int|null $pipelines_count
 * @property-read User|null $reviewedBy
 * @property-read Tenant $tenant
 *
 * @method static UploadedDocumentFactory factory($count = null, $state = [])
 * @method static Builder<static>|UploadedDocument newModelQuery()
 * @method static Builder<static>|UploadedDocument newQuery()
 * @method static Builder<static>|UploadedDocument query()
 * @method static Builder<static>|UploadedDocument whereCreatedAt($value)
 * @method static Builder<static>|UploadedDocument whereDisk($value)
 * @method static Builder<static>|UploadedDocument whereDocumentRequestId($value)
 * @method static Builder<static>|UploadedDocument whereExtractedText($value)
 * @method static Builder<static>|UploadedDocument whereId($value)
 * @method static Builder<static>|UploadedDocument whereMimeType($value)
 * @method static Builder<static>|UploadedDocument whereOriginalFilename($value)
 * @method static Builder<static>|UploadedDocument wherePath($value)
 * @method static Builder<static>|UploadedDocument whereProcessingError($value)
 * @method static Builder<static>|UploadedDocument whereProcessingFinishedAt($value)
 * @method static Builder<static>|UploadedDocument whereProcessingStartedAt($value)
 * @method static Builder<static>|UploadedDocument whereProcessingStatus($value)
 * @method static Builder<static>|UploadedDocument whereRejectionReason($value)
 * @method static Builder<static>|UploadedDocument whereReviewedAt($value)
 * @method static Builder<static>|UploadedDocument whereReviewedBy($value)
 * @method static Builder<static>|UploadedDocument whereSizeBytes($value)
 * @method static Builder<static>|UploadedDocument whereTenantId($value)
 * @method static Builder<static>|UploadedDocument whereTextractJobId($value)
 * @method static Builder<static>|UploadedDocument whereUpdatedAt($value)
 * @method static Builder<static>|UploadedDocument whereUploadedAt($value)
 *
 * @mixin \Eloquent
 */
final class UploadedDocument extends TenantOwnedModel
{
    /** @use HasFactory<UploadedDocumentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'document_request_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'uploaded_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'processing_status',
        'extracted_text',
        'processing_error',
        'processing_started_at',
        'processing_finished_at',
        'textract_job_id',
    ];

    /**
     * @return BelongsTo<DocumentRequest, $this>
     */
    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return MorphMany<Pipeline, $this>
     */
    public function pipelines(): MorphMany
    {
        return $this->morphMany(Pipeline::class, 'pipelineable');
    }

    /**
     * Most recent run only. Staff list rows show one status chip; loading
     * every retry's steps is unbounded as documents are reprocessed.
     *
     * @return MorphOne<Pipeline, $this>
     */
    public function latestPipeline(): MorphOne
    {
        return $this->morphOne(Pipeline::class, 'pipelineable')->latestOfMany();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'processing_status' => DocumentProcessingStatus::class,
            'processing_started_at' => 'datetime',
            'processing_finished_at' => 'datetime',
        ];
    }
}
