<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Enums\DossierExportStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\DossierExportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Override;

/**
 * Queued ZIP export of a dossier and its submitted documents.
 *
 * created_at is nullable in DDL but Eloquent always writes it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $dossier_id
 * @property int|null $requested_by
 * @property DossierExportStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $error
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Dossier|null $dossier
 * @property-read Pipeline|null $pipeline
 * @property-read User|null $requestedBy
 * @property-read Tenant $tenant
 *
 * @method static DossierExportFactory factory($count = null, $state = [])
 * @method static Builder<static>|DossierExport newModelQuery()
 * @method static Builder<static>|DossierExport newQuery()
 * @method static Builder<static>|DossierExport query()
 * @method static Builder<static>|DossierExport whereCompletedAt($value)
 * @method static Builder<static>|DossierExport whereCreatedAt($value)
 * @method static Builder<static>|DossierExport whereDisk($value)
 * @method static Builder<static>|DossierExport whereDossierId($value)
 * @method static Builder<static>|DossierExport whereError($value)
 * @method static Builder<static>|DossierExport whereExpiresAt($value)
 * @method static Builder<static>|DossierExport whereFailedAt($value)
 * @method static Builder<static>|DossierExport whereId($value)
 * @method static Builder<static>|DossierExport wherePath($value)
 * @method static Builder<static>|DossierExport whereRequestedBy($value)
 * @method static Builder<static>|DossierExport whereStatus($value)
 * @method static Builder<static>|DossierExport whereTenantId($value)
 * @method static Builder<static>|DossierExport whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class DossierExport extends TenantOwnedModel
{
    /** @use HasFactory<DossierExportFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'dossier_id',
        'requested_by',
        'status',
        'disk',
        'path',
        'error',
        'expires_at',
        'completed_at',
        'failed_at',
    ];

    /**
     * @return BelongsTo<Dossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return MorphOne<Pipeline, $this>
     */
    public function pipeline(): MorphOne
    {
        return $this->morphOne(Pipeline::class, 'pipelineable');
    }

    public function isDownloadable(): bool
    {
        return $this->status === DossierExportStatus::Completed
            && $this->disk !== null
            && $this->path !== null
            && $this->expires_at?->isFuture() === true;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => DossierExportStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
