<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\Audit\Concerns\LogsTenantAuditableActivity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Reporting\Concerns\InvalidatesWorkspaceDashboardCache;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\DossierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $client_id
 * @property string $title
 * @property string|null $reference
 * @property DossierStatus $status
 * @property int|null $responsible_user_id
 * @property Carbon|null $due_date
 * @property int|null $reminder_interval_days
 * @property Carbon|null $next_reminder_at
 * @property Carbon|null $last_client_message_sent_at
 * @property Carbon|null $last_client_opened_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'tenant_id',
    'client_id',
    'title',
    'reference',
    'status',
    'responsible_user_id',
    'due_date',
    'reminder_interval_days',
])]
#[UseFactory(DossierFactory::class)]
final class Dossier extends Model
{
    /** @use HasFactory<DossierFactory> */
    use BelongsToTenant, HasFactory, InvalidatesWorkspaceDashboardCache, LogsActivity, LogsTenantAuditableActivity, Searchable, SoftDeletes;

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
