<?php

declare(strict_types=1);

namespace App\Clients\Models;

use Override;
use App\Audit\Concerns\LogsTenantAuditableActivity;
use App\Audit\Models\Activity;
use App\Dossiers\Models\Dossier;
use App\Reporting\Concerns\InvalidatesWorkspaceDashboardCache;
use App\Tenancy\Concerns\BelongsToTenant;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Collection<int, Activity> $activitiesAsSubject
 * @property-read int|null $activities_as_subject_count
 * @property-read Collection<int, Dossier> $dossiers
 * @property-read int|null $dossiers_count
 * @property-read Tenant $tenant
 *
 * @method static ClientFactory factory($count = null, $state = [])
 * @method static Builder<static>|Client newModelQuery()
 * @method static Builder<static>|Client newQuery()
 * @method static Builder<static>|Client onlyTrashed()
 * @method static Builder<static>|Client query()
 * @method static Builder<static>|Client whereCreatedAt($value)
 * @method static Builder<static>|Client whereDeletedAt($value)
 * @method static Builder<static>|Client whereEmail($value)
 * @method static Builder<static>|Client whereId($value)
 * @method static Builder<static>|Client whereName($value)
 * @method static Builder<static>|Client whereTenantId($value)
 * @method static Builder<static>|Client whereUpdatedAt($value)
 * @method static Builder<static>|Client withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Client withoutTrashed()
 *
 * @mixin Model
 */
final class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToTenant, HasFactory, InvalidatesWorkspaceDashboardCache, LogsActivity, LogsTenantAuditableActivity, Searchable, SoftDeletes;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
    ];

    /**
     * Columns Scout's database engine searches with LIKE.
     *
     * @return array{id: int, name: string, email: string}
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
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
     * @return HasMany<Dossier, $this>
     */
    public function dossiers(): HasMany
    {
        return $this->hasMany(Dossier::class);
    }
}
