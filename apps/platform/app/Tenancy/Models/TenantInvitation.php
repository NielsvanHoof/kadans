<?php

declare(strict_types=1);

namespace App\Tenancy\Models;

use App\Accounts\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\TenantInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Pending staff invite into a workspace. Looked up by hashed token before a
 * tenant is current (accept links), so callers must use queryWithoutTenantScope().
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string $role
 * @property string $token
 * @property int $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $invitedBy
 * @property-read Tenant $tenant
 *
 * @method static TenantInvitationFactory factory($count = null, $state = [])
 * @method static Builder<static>|TenantInvitation newModelQuery()
 * @method static Builder<static>|TenantInvitation newQuery()
 * @method static Builder<static>|TenantInvitation query()
 * @method static Builder<static>|TenantInvitation whereAcceptedAt($value)
 * @method static Builder<static>|TenantInvitation whereCreatedAt($value)
 * @method static Builder<static>|TenantInvitation whereEmail($value)
 * @method static Builder<static>|TenantInvitation whereExpiresAt($value)
 * @method static Builder<static>|TenantInvitation whereId($value)
 * @method static Builder<static>|TenantInvitation whereInvitedBy($value)
 * @method static Builder<static>|TenantInvitation whereRevokedAt($value)
 * @method static Builder<static>|TenantInvitation whereRole($value)
 * @method static Builder<static>|TenantInvitation whereTenantId($value)
 * @method static Builder<static>|TenantInvitation whereToken($value)
 * @method static Builder<static>|TenantInvitation whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class TenantInvitation extends TenantOwnedModel
{
    /** @use HasFactory<TenantInvitationFactory> */
    use HasFactory, MassPrunable;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $hidden = [
        'token',
    ];

    public static function hashToken(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    /**
     * Apply "still pending" constraints to an invitation query.
     *
     * @param  Builder<TenantInvitation>  $query
     */
    public static function constrainToPending(Builder $query): void
    {
        $query->getQuery()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * @return Builder<TenantInvitation>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays((int) config('retention.member_invitations_days'));

        return self::queryWithoutTenantScope()
            ->where(function (Builder $query) use ($cutoff): void {
                $query->getQuery()
                    ->whereNotNull('revoked_at')
                    ->where('revoked_at', '<=', $cutoff);
            })
            ->orWhere(function (Builder $query) use ($cutoff): void {
                $query->getQuery()
                    ->whereNotNull('accepted_at')
                    ->where('accepted_at', '<=', $cutoff);
            })
            ->orWhere(function (Builder $query) use ($cutoff): void {
                $query->getQuery()
                    ->whereNull('revoked_at')
                    ->whereNull('accepted_at')
                    ->where('expires_at', '<=', $cutoff);
            });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
