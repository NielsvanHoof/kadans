<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\DataSubjectRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A logged access or erasure request from one client.
 *
 * Lives in Platform rather than Clients because fulfilling one has to reach
 * across Dossiers, DocumentProcessing, AudioProcessing, ClientPortal and the
 * retention actions — only the top layer may depend on all of those.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $client_id
 * @property DataSubjectRequestType $type
 * @property DataSubjectRequestStatus $status
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable $due_at
 * @property int|null $requested_by
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by
 * @property CarbonImmutable|null $refused_at
 * @property int|null $refused_by
 * @property string|null $refusal_reason
 * @property string|null $disk
 * @property string|null $path
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client $client
 * @property-read User|null $completedBy
 * @property-read User|null $refusedBy
 * @property-read User|null $requestedBy
 * @property-read Tenant $tenant
 *
 * @method static DataSubjectRequestFactory factory($count = null, $state = [])
 * @method static Builder<static>|DataSubjectRequest newModelQuery()
 * @method static Builder<static>|DataSubjectRequest newQuery()
 * @method static Builder<static>|DataSubjectRequest query()
 *
 * @mixin \Eloquent
 */
final class DataSubjectRequest extends TenantOwnedModel
{
    /** @use HasFactory<DataSubjectRequestFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'client_id',
        'type',
        'status',
        'requested_at',
        'due_at',
        'requested_by',
        'completed_at',
        'completed_by',
        'refused_at',
        'refused_by',
        'refusal_reason',
        'disk',
        'path',
        'expires_at',
    ];

    /**
     * Soft-deleted (archived) clients must still resolve: the compliance list
     * is the record of what was asked about whom, and archival must not blank
     * those rows. Hard-purged clients stay null — fromModel handles that.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function refusedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refused_by');
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_at->isPast();
    }

    /**
     * An export artefact is only downloadable while it exists and is unexpired.
     */
    public function isDownloadable(): bool
    {
        return $this->type === DataSubjectRequestType::Export
            && $this->status === DataSubjectRequestStatus::Completed
            && $this->disk !== null
            && $this->path !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => DataSubjectRequestType::class,
            'status' => DataSubjectRequestStatus::class,
            'requested_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'refused_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
