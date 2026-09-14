<?php

declare(strict_types=1);

namespace App\Questionnaires\Models;

use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Database\Factories\QuestionnaireTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;
use Override;

/**
 * Reusable questionnaire pack — system-wide (tenant_id null) or firm-owned.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string|null $description
 * @property QuestionnaireTemplateCategory $category
 * @property int|null $source_template_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, QuestionnaireTemplateItem> $items
 * @property-read int|null $items_count
 * @property-read QuestionnaireTemplate|null $sourceTemplate
 * @property-read Tenant|null $tenant
 *
 * @method static QuestionnaireTemplateFactory factory($count = null, $state = [])
 * @method static Builder<static>|QuestionnaireTemplate newModelQuery()
 * @method static Builder<static>|QuestionnaireTemplate newQuery()
 * @method static Builder<static>|QuestionnaireTemplate query()
 * @method static Builder<static>|QuestionnaireTemplate whereCategory($value)
 * @method static Builder<static>|QuestionnaireTemplate whereCreatedAt($value)
 * @method static Builder<static>|QuestionnaireTemplate whereDescription($value)
 * @method static Builder<static>|QuestionnaireTemplate whereId($value)
 * @method static Builder<static>|QuestionnaireTemplate whereName($value)
 * @method static Builder<static>|QuestionnaireTemplate whereSourceTemplateId($value)
 * @method static Builder<static>|QuestionnaireTemplate whereTenantId($value)
 * @method static Builder<static>|QuestionnaireTemplate whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class QuestionnaireTemplate extends Model
{
    /** @use HasFactory<QuestionnaireTemplateFactory> */
    use HasFactory, Searchable;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'category',
        'source_template_id',
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'category' => 'custom',
    ];

    /**
     * System templates plus templates owned by the current tenant.
     *
     * Static starter avoids Larastan/strict-rules treating local scopes as
     * static methods called dynamically on the Eloquent builder.
     *
     * @return Builder<QuestionnaireTemplate>
     */
    public static function queryVisibleToCurrentTenant(): Builder
    {
        $query = self::query();
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            return $query->where('tenant_id', null);
        }

        return $query->where(function (Builder $builder) use ($tenant): void {
            $builder->where('tenant_id', null)
                ->orWhere('tenant_id', $tenant->getKey());
        });
    }

    /**
     * Columns Scout's database engine searches with LIKE.
     *
     * Omit integer `id`: DatabaseEngine ILIKEs every key, and Postgres has no
     * bigint ILIKE — a name search would 500.
     *
     * @return array{name: string, description: string}
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description ?? '',
        ];
    }

    /**
     * Constrain Scout's database engine to templates this firm can see.
     *
     * Without this the engine builds from newQuery(). This model is not a
     * TenantOwnedModel — tenant_id is nullable so system templates stay shared —
     * so that query carries no tenant filter, and `scout.key_limit` truncates a
     * window drawn from every firm's templates *before* the caller's visibility
     * filter runs. A firm's own templates then silently fall out of its own
     * search results as the platform takes on more firms.
     *
     * Collection engine (tests) ignores this and builds from query().
     *
     * @param  ScoutBuilder<QuestionnaireTemplate>  $_builder
     * @return Builder<QuestionnaireTemplate>
     */
    public function newScoutQuery(ScoutBuilder $_builder): Builder
    {
        return self::queryVisibleToCurrentTenant();
    }

    public function isSystem(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * Only resolve system templates or templates owned by the current tenant.
     */
    #[Override]
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        return self::queryVisibleToCurrentTenant()
            ->where($field, $value)
            ->first();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<QuestionnaireTemplate, $this>
     */
    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_template_id');
    }

    /**
     * @return HasMany<QuestionnaireTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuestionnaireTemplateItem::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'category' => QuestionnaireTemplateCategory::class,
        ];
    }
}
