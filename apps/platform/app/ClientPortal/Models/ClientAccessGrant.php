<?php

declare(strict_types=1);

namespace App\ClientPortal\Models;

use App\Accounts\Models\User;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Concerns\BelongsToTenant;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Database\Factories\ClientAccessGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
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
final class ClientAccessGrant extends Model
{
    /** @use HasFactory<ClientAccessGrantFactory> */
    use BelongsToTenant, HasFactory, MassPrunable;

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
        'created_by',
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

        return self::query()
            ->withoutTenantScope()
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
        ];
    }
}
