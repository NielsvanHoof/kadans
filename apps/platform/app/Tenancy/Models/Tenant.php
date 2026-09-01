<?php

declare(strict_types=1);

namespace App\Tenancy\Models;

use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Tenancy\Support\TenantLookup;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;
use Spatie\Multitenancy\TenantCollection;

use function in_array;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Client> $clients
 * @property-read int|null $clients_count
 * @property-read Collection<int, Dossier> $dossiers
 * @property-read int|null $dossiers_count
 * @property-read Collection<int, TenantMembership> $memberships
 * @property-read int|null $memberships_count
 *
 * @method static TenantCollection<int, static> all($columns = ['*'])
 * @method static TenantFactory factory($count = null, $state = [])
 * @method static TenantCollection<int, static> get($columns = ['*'])
 * @method static Builder<static>|Tenant newModelQuery()
 * @method static Builder<static>|Tenant newQuery()
 * @method static Builder<static>|Tenant query()
 * @method static Builder<static>|Tenant whereCreatedAt($value)
 * @method static Builder<static>|Tenant whereId($value)
 * @method static Builder<static>|Tenant whereName($value)
 * @method static Builder<static>|Tenant whereSlug($value)
 * @method static Builder<static>|Tenant whereUpdatedAt($value)
 *
 * @property-read TenantSetting|null $settings
 *
 * @mixin \Eloquent
 */
#[RouteKey('slug')]
final class Tenant extends Model implements IsTenant
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, ImplementsTenant, UsesMultitenancyConfig;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return HasMany<Dossier, $this>
     */
    public function dossiers(): HasMany
    {
        return $this->hasMany(Dossier::class);
    }

    /**
     * @return HasOne<TenantSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    /**
     * Scoped bindings under this tenant. System questionnaire templates
     * (tenant_id null) are intentionally visible across firms, so they
     * must not be resolved through a strict hasMany(tenant_id) relation.
     */
    #[Override]
    public function resolveChildRouteBinding($childType, $value, $field)
    {
        if (in_array($childType, ['template', 'templates'], true)) {
            $field ??= (new QuestionnaireTemplate)->getRouteKeyName();

            return QuestionnaireTemplate::queryVisibleToCurrentTenant()
                ->where($field, $value)
                ->first();
        }

        return parent::resolveChildRouteBinding($childType, $value, $field);
    }

    /**
     * Directory cache lives in TenantLookup. Forget from here — no Observer
     * class — so a slug rename cannot 404 for the rest of the TTL.
     */
    #[Override]
    protected static function booted(): void
    {
        self::updated(static function (Tenant $tenant): void {
            TenantLookup::forget($tenant);
        });

        self::deleted(static function (Tenant $tenant): void {
            TenantLookup::forget($tenant);
        });
    }
}
