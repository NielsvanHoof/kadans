<?php

declare(strict_types=1);

namespace App\Tenancy\Models;

use App\Accounts\Models\User;
use App\Tenancy\Enums\TenantMembershipStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string|null $job_title
 * @property TenantMembershipStatus $status
 * @property CarbonImmutable|null $invited_at
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $last_accessed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Tenant $tenant
 * @property-read User $user
 *
 * @method static TenantMembershipFactory factory($count = null, $state = [])
 * @method static Builder<static>|TenantMembership newModelQuery()
 * @method static Builder<static>|TenantMembership newQuery()
 * @method static Builder<static>|TenantMembership query()
 * @method static Builder<static>|TenantMembership whereCreatedAt($value)
 * @method static Builder<static>|TenantMembership whereId($value)
 * @method static Builder<static>|TenantMembership whereInvitedAt($value)
 * @method static Builder<static>|TenantMembership whereJobTitle($value)
 * @method static Builder<static>|TenantMembership whereJoinedAt($value)
 * @method static Builder<static>|TenantMembership whereLastAccessedAt($value)
 * @method static Builder<static>|TenantMembership whereStatus($value)
 * @method static Builder<static>|TenantMembership whereTenantId($value)
 * @method static Builder<static>|TenantMembership whereUpdatedAt($value)
 * @method static Builder<static>|TenantMembership whereUserId($value)
 *
 * @mixin \Eloquent
 */
final class TenantMembership extends Model
{
    /** @use HasFactory<TenantMembershipFactory> */
    use HasFactory;

    /**
     * How stale last_accessed_at may get before it is written again.
     */
    public const int ACCESS_RECORD_THROTTLE_SECONDS = 300;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'job_title',
        'last_accessed_at',
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $hidden = [
        'status',
        'invited_at',
        'joined_at',
        'last_accessed_at',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantMembershipStatus::Active;
    }

    /**
     * Bind only memberships for the current workspace.
     *
     * This model is not TenantOwnedModel (memberships participate in tenant
     * bootstrap), so TenantScope does not apply. Without this, a numeric id
     * from another firm would resolve and rely on a controller abort.
     */
    #[Override]
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return $this->newQuery()
            ->where($this->qualifyColumn($field), $value)
            ->where($this->qualifyColumn('tenant_id'), $tenant->getKey())
            ->first();
    }

    /**
     * Whether last_accessed_at was written recently enough to leave alone.
     *
     * The value exists so a workspace can show roughly when a member was last
     * here, which nothing renders to the minute — the members list formats it
     * as a date. Writing it on every request meant an UPDATE (two columns,
     * since the model keeps timestamps) on every page, every Inertia partial
     * reload, every deferred-prop XHR and every poll, for information nobody
     * reads at that resolution.
     *
     * The check reads the already-loaded attribute, so throttling costs no
     * query at all — not even a cache round-trip.
     */
    public function hasRecentAccessRecorded(): bool
    {
        return $this->last_accessed_at !== null
            && $this->last_accessed_at->gt(now()->subSeconds(self::ACCESS_RECORD_THROTTLE_SECONDS));
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => TenantMembershipStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }
}
