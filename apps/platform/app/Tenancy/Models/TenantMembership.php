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
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'user_id',
        'job_title',
        'status',
        'invited_at',
        'joined_at',
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
