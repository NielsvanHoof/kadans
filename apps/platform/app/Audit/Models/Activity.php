<?php

declare(strict_types=1);

namespace App\Audit\Models;

use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;
use Override;
use RuntimeException;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property int|null $tenant_id
 * @property-read Tenant|null $tenant
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
