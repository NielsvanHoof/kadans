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
 * @mixin Model
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
        // self::query() (final class) keeps Builder<QuestionnaireTemplate> invariant.
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
     * @return array{id: int, name: string, description: string}
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description ?? '',
        ];
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
