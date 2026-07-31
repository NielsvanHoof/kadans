<?php

declare(strict_types=1);

namespace App\Audit\Models;

use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;
use Override;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property int $id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $event
 * @property string|null $causer_type
 * @property int|null $causer_id
 * @property Collection<array-key, mixed>|null $attribute_changes
 * @property Collection<array-key, mixed>|null $properties
 * @property int|null $tenant_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model|null $causer
 * @property-read Model|null $subject
 * @property-read Tenant|null $tenant
 *
 * @method static Builder<static>|Activity causedBy(Model $causer)
 * @method static Builder<static>|Activity forEvent(ActivityEvent|string $event)
 * @method static Builder<static>|Activity forSubject(Model $subject)
 * @method static Builder<static>|Activity forTenant(Tenant $tenant)
 * @method static Builder<static>|Activity inLog(array|string ...$logNames)
 * @method static Builder<static>|Activity newModelQuery()
 * @method static Builder<static>|Activity newQuery()
 * @method static Builder<static>|Activity query()
 * @method static Builder<static>|Activity whereAttributeChanges($value)
 * @method static Builder<static>|Activity whereCauserId($value)
 * @method static Builder<static>|Activity whereCauserType($value)
 * @method static Builder<static>|Activity whereCreatedAt($value)
 * @method static Builder<static>|Activity whereDescription($value)
 * @method static Builder<static>|Activity whereEvent($value)
 * @method static Builder<static>|Activity whereId($value)
 * @method static Builder<static>|Activity whereLogName($value)
 * @method static Builder<static>|Activity whereProperties($value)
 * @method static Builder<static>|Activity whereSubjectId($value)
 * @method static Builder<static>|Activity whereSubjectType($value)
 * @method static Builder<static>|Activity whereTenantId($value)
 * @method static Builder<static>|Activity whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class Activity extends SpatieActivity
{
    use Searchable;

    /**
     * Columns Scout's database engine searches with LIKE.
     *
     * @return array{id: int, description: string, event: string}
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description ?? '',
            'event' => $this->event ?? '',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Audit log records are immutable.');
        });
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    #[Scope]
    protected function forTenant(Builder $query, Tenant $tenant): Builder
    {
        return $query->where('tenant_id', $tenant->id);
    }
}
