<?php

declare(strict_types=1);

namespace App\ClientPortal\Models;

use App\Accounts\Models\User;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\ClientAccessGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $dossier_id
 * @property string $token
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $device_fingerprint
 * @property CarbonImmutable|null $new_device_seen_at
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $createdBy
 * @property-read Dossier|null $dossier
 * @property-read Tenant $tenant
 *
 * @method static ClientAccessGrantFactory factory($count = null, $state = [])
 * @method static Builder<static>|ClientAccessGrant newModelQuery()
 * @method static Builder<static>|ClientAccessGrant newQuery()
 * @method static Builder<static>|ClientAccessGrant query()
 * @method static Builder<static>|ClientAccessGrant valid()
 * @method static Builder<static>|ClientAccessGrant whereCreatedAt($value)
 * @method static Builder<static>|ClientAccessGrant whereCreatedBy($value)
 * @method static Builder<static>|ClientAccessGrant whereDossierId($value)
 * @method static Builder<static>|ClientAccessGrant whereExpiresAt($value)
 * @method static Builder<static>|ClientAccessGrant whereId($value)
 * @method static Builder<static>|ClientAccessGrant whereLastUsedAt($value)
 * @method static Builder<static>|ClientAccessGrant whereRevokedAt($value)
 * @method static Builder<static>|ClientAccessGrant whereTenantId($value)
 * @method static Builder<static>|ClientAccessGrant whereToken($value)
 * @method static Builder<static>|ClientAccessGrant whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class ClientAccessGrant extends TenantOwnedModel
{
    /** @use HasFactory<ClientAccessGrantFactory> */
    use HasFactory, MassPrunable;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'dossier_id',
        'token',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'device_fingerprint',
        'new_device_seen_at',
        'created_by',
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $hidden = [
        'token',
        // A hash, not a secret — but nothing client-facing has any use for it.
        'device_fingerprint',
    ];

    public static function hashToken(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    /**
     * @return BelongsTo<Dossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * @return Builder<ClientAccessGrant>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays((int) config('retention.portal_grants_days'));

        return self::queryWithoutTenantScope()
            ->where(function (Builder $query) use ($cutoff): void {
                $query->getQuery()
                    ->whereNotNull('revoked_at')
                    ->where('revoked_at', '<=', $cutoff);
            })
            ->orWhere(function (Builder $query) use ($cutoff): void {
                $query->getQuery()
                    ->whereNull('revoked_at')
                    ->where('expires_at', '<=', $cutoff);
            });
    }

    /**
     * @param  Builder<ClientAccessGrant>  $query
     * @return Builder<ClientAccessGrant>
     */
    #[Scope]
    protected function valid(Builder $query): Builder
    {
        return $query
            ->where('revoked_at', null)
            ->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'new_device_seen_at' => 'datetime',
        ];
    }
}
