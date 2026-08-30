<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\AudioRecordingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Override;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $dossier_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property CarbonImmutable $uploaded_at
 * @property int|null $uploaded_by
 * @property TranscriptionStatus $transcription_status
 * @property string|null $transcribe_job_id
 * @property string|null $transcript
 * @property array<int, array{text: string, speaker: string, start_seconds: float, end_seconds: float}>|null $segments
 * @property string|null $transcription_error
 * @property CarbonImmutable|null $transcription_started_at
 * @property CarbonImmutable|null $transcription_finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Dossier $dossier
 * @property-read Collection<int, AudioRecordingCalendarLink> $calendarLinks
 * @property-read int|null $calendar_links_count
 * @property-read Pipeline|null $latestPipeline
 * @property-read Collection<int, Pipeline> $pipelines
 * @property-read int|null $pipelines_count
 * @property-read User|null $uploadedBy
 * @property-read Tenant $tenant
 *
 * @method static AudioRecordingFactory factory($count = null, $state = [])
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> query()
 * @method static Builder<static> whereCreatedAt($value)
 * @method static Builder<static> whereDisk($value)
 * @method static Builder<static> whereDossierId($value)
 * @method static Builder<static> whereId($value)
 * @method static Builder<static> whereMimeType($value)
 * @method static Builder<static> whereOriginalFilename($value)
 * @method static Builder<static> wherePath($value)
 * @method static Builder<static> whereSizeBytes($value)
 * @method static Builder<static> whereTenantId($value)
 * @method static Builder<static> whereTranscript($value)
 * @method static Builder<static> whereTranscriptionError($value)
 * @method static Builder<static> whereTranscriptionFinishedAt($value)
 * @method static Builder<static> whereTranscriptionStartedAt($value)
 * @method static Builder<static> whereTranscriptionStatus($value)
 * @method static Builder<static> whereUpdatedAt($value)
 * @method static Builder<static> whereUploadedAt($value)
 * @method static Builder<static> whereUploadedBy($value)
 * @method static Builder<static>|AudioRecording whereSegments($value)
 * @method static Builder<static>|AudioRecording whereTranscribeJobId($value)
 *
 * @mixin \Eloquent
 */
final class AudioRecording extends TenantOwnedModel
{
    /** @use HasFactory<AudioRecordingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'dossier_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'uploaded_at',
        'uploaded_by',
        'transcription_status',
        'transcribe_job_id',
        'transcript',
        'segments',
        'transcription_error',
        'transcription_started_at',
        'transcription_finished_at',
    ];

    /**
     * @return BelongsTo<Dossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    /**
     * Calendar events this transcript was attached to (any provider).
     *
     * @return HasMany<AudioRecordingCalendarLink, $this>
     */
    public function calendarLinks(): HasMany
    {
        return $this->hasMany(AudioRecordingCalendarLink::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return MorphMany<Pipeline, $this>
     */
    public function pipelines(): MorphMany
    {
        return $this->morphMany(Pipeline::class, 'pipelineable');
    }

    /**
     * Most recent run only. The audio list chip shows one status; retry
     * history lives on the recording show page, not this row.
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
            'transcription_status' => TranscriptionStatus::class,
            'segments' => 'array',
            'transcription_started_at' => 'datetime',
            'transcription_finished_at' => 'datetime',
        ];
    }
}
