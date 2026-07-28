<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Enums\DossierExportStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\DossierExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * Queued ZIP export of a dossier and its submitted documents.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $dossier_id
 * @property int|null $requested_by
 * @property DossierExportStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $error
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
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
])]
#[UseFactory(DossierExportFactory::class)]
final class DossierExport extends Model
{
    /** @use HasFactory<DossierExportFactory> */
    use BelongsToTenant, HasFactory;

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
