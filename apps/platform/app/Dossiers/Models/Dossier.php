<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\Audit\Concerns\LogsTenantAuditableActivity;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Shared\Concerns\InvalidatesTenantCaches;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\DossierFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

use function is_string;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string|null $reference
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int $client_id
 * @property DossierStatus $status
 * @property CarbonImmutable|null $deleted_at
 * @property int|null $responsible_user_id
 * @property CarbonImmutable|null $due_date
 * @property int|null $reminder_interval_days
 * @property CarbonImmutable|null $next_reminder_at
 * @property CarbonImmutable|null $last_client_message_sent_at
 * @property CarbonImmutable|null $last_client_opened_at
 * @property int|null $builder_locked_by_user_id
 * @property string|null $builder_lock_token
 * @property CarbonImmutable|null $builder_lock_expires_at
 * @property-read EloquentCollection<int, Activity> $activitiesAsSubject
 * @property-read int|null $activities_as_subject_count
 * @property-read User|null $builderLockedBy
 * @property-read Client|null $client
 * @property-read EloquentCollection<int, ClientAccessGrant> $clientAccessGrants
 * @property-read int|null $client_access_grants_count
 * @property-read EloquentCollection<int, DocumentRequest> $documentRequests
 * @property-read int|null $document_requests_count
 * @property-read EloquentCollection<int, DossierExport> $exports
 * @property-read int|null $exports_count
 * @property-read User|null $responsibleUser
 * @property-read Tenant $tenant
 *
 * @method static DossierFactory factory($count = null, $state = [])
 * @method static EloquentBuilder<static>|Dossier newModelQuery()
 * @method static EloquentBuilder<static>|Dossier newQuery()
 * @method static EloquentBuilder<static>|Dossier onlyTrashed()
 * @method static EloquentBuilder<static>|Dossier query()
 * @method static EloquentBuilder<static>|Dossier whereClientId($value)
 * @method static EloquentBuilder<static>|Dossier whereCreatedAt($value)
 * @method static EloquentBuilder<static>|Dossier whereDeletedAt($value)
 * @method static EloquentBuilder<static>|Dossier whereDueDate($value)
 * @method static EloquentBuilder<static>|Dossier whereId($value)
 * @method static EloquentBuilder<static>|Dossier whereLastClientMessageSentAt($value)
 * @method static EloquentBuilder<static>|Dossier whereLastClientOpenedAt($value)
 * @method static EloquentBuilder<static>|Dossier whereNextReminderAt($value)
 * @method static EloquentBuilder<static>|Dossier whereReference($value)
 * @method static EloquentBuilder<static>|Dossier whereReminderIntervalDays($value)
 * @method static EloquentBuilder<static>|Dossier whereResponsibleUserId($value)
 * @method static EloquentBuilder<static>|Dossier whereStatus($value)
 * @method static EloquentBuilder<static>|Dossier whereTenantId($value)
 * @method static EloquentBuilder<static>|Dossier whereTitle($value)
 * @method static EloquentBuilder<static>|Dossier whereUpdatedAt($value)
 * @method static EloquentBuilder<static>|Dossier withTrashed(bool $withTrashed = true)
 * @method static EloquentBuilder<static>|Dossier withoutTrashed()
 *
 * @mixin \Eloquent
 */
final class Dossier extends TenantOwnedModel
{
    /** @use HasFactory<DossierFactory> */
    use HasFactory, InvalidatesTenantCaches, LogsActivity, LogsTenantAuditableActivity, Searchable, SoftDeletes;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'client_id',
        'title',
        'reference',
        'status',
        'responsible_user_id',
        'due_date',
        'reminder_interval_days',
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Join clients so Scout's database engine can LIKE on clients.name.
     * Collection engine (tests) ignores this and uses toSearchableArray values.
     *
     * @param  ScoutBuilder<Dossier>  $_builder
     * @return EloquentBuilder<Dossier>
     */
    public function newScoutQuery(ScoutBuilder $_builder): EloquentBuilder
    {
        $query = self::query();

        $query->getQuery()
            ->select($this->qualifyColumn('*'))
            ->leftJoin('clients', 'clients.id', '=', $this->qualifyColumn('client_id'));

        return $query;
    }

    /**
     * Columns Scout's database engine searches with LIKE.
     *
     * `clients.name` is a qualified column (dot kept as-is by qualifyColumn) so
     * archive/live search can match the related client without a denormalized field.
     *
     * @return array{id: int, title: string, reference: string, 'clients.name': string}
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'reference' => $this->reference ?? '',
            // Empty on the fresh model Scout uses for column discovery (avoids N+1).
            // Loaded for collection-engine hits when the model exists in the DB.
            'clients.name' => $this->clientNameForSearch(),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(config('activitylog.default_log_name'))
            ->logOnly($this->auditableAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * Staff member currently holding the exclusive builder edit lease.
     *
     * @return BelongsTo<User, $this>
     */
    public function builderLockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'builder_locked_by_user_id');
    }

    /**
     * True when a non-expired builder lock is present.
     */
    public function hasActiveBuilderLock(): bool
    {
        return $this->builder_locked_by_user_id !== null
            && is_string($this->builder_lock_token)
            && $this->builder_lock_token !== ''
            && $this->builder_lock_expires_at !== null
            && $this->builder_lock_expires_at->isFuture();
    }

    /**
     * True when the given user holds a still-valid builder lock.
     */
    public function isBuilderLockedBy(User $user): bool
    {
        return $this->hasActiveBuilderLock()
            && (int) $this->builder_locked_by_user_id === (int) $user->getKey();
    }

    /**
     * Clear builder lock columns without touching fillable / activity log.
     */
    public function clearBuilderLock(): void
    {
        $this->forceFill([
            'builder_locked_by_user_id' => null,
            'builder_lock_token' => null,
            'builder_lock_expires_at' => null,
        ])->save();
    }

    /**
     * @return HasMany<DocumentRequest, $this>
     */
    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    /**
     * Document requests in canvas order.
     *
     * This is the order clients answer them in, and the order visibility rules
     * treat as "earlier", so both are read from one place. The id tiebreak matches
     * DocumentRequest::isOrderedBefore().
     *
     * @return EloquentCollection<int, DocumentRequest>
     */
    public function documentRequestsInCanvasOrder(): EloquentCollection
    {
        $query = DocumentRequest::query();
        $query->getQuery()->where('dossier_id', $this->getKey());
        $query->getQuery()->orderBy('sort_order');
        $query->getQuery()->orderBy('id');

        return $query->get();
    }

    /**
     * @return HasMany<ClientAccessGrant, $this>
     */
    public function clientAccessGrants(): HasMany
    {
        return $this->hasMany(ClientAccessGrant::class);
    }

    /**
     * @return HasMany<DossierExport, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(DossierExport::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => DossierStatus::class,
            'due_date' => 'date',
            'reminder_interval_days' => 'integer',
            'next_reminder_at' => 'datetime',
            'last_client_message_sent_at' => 'datetime',
            'last_client_opened_at' => 'datetime',
            'builder_lock_expires_at' => 'datetime',
        ];
    }

    /**
     * Resolve client name for Scout without querying during column discovery.
     */
    private function clientNameForSearch(): string
    {
        if ($this->relationLoaded('client')) {
            $client = $this->client;

            return $client instanceof Client ? $client->name : '';
        }

        if (! $this->exists) {
            return '';
        }
        $clientQuery = Client::query()->whereKey($this->client_id);
        $clientQuery->withoutGlobalScope(SoftDeletingScope::class);

        return (string) ($clientQuery->toBase()->value('name') ?? '');
    }
}
