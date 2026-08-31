<?php

declare(strict_types=1);

namespace App\Accounts\Models;

use App\Accounts\Enums\PlatformRole;
use App\Accounts\Support\DisplayTimezones;
use App\Integrations\Models\OAuthConnection;
use App\Tenancy\Contracts\SupportSessionState;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Config;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Passkey;
use Override;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

use function array_key_exists;
use function is_string;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $locale
 * @property string|null $timezone
 * @property PlatformRole|null $platform_role
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, OAuthConnection> $oauthConnections
 * @property-read int|null $oauth_connections_count
 * @property-read Collection<int, Passkey> $passkeys
 * @property-read int|null $passkeys_count
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, TenantRole> $roles
 * @property-read int|null $roles_count
 * @property-read Collection<int, TenantMembership> $tenantMemberships
 * @property-read int|null $tenant_memberships_count
 *
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User permission($permissions, bool $without = false)
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User role($roles, ?string $guard = null, bool $without = false)
 * @method static Builder<static>|User team($teams, bool $without = false)
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereEmailVerifiedAt($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereLocale($value)
 * @method static Builder<static>|User whereName($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User wherePlatformRole($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static Builder<static>|User whereTwoFactorSecret($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 * @method static Builder<static>|User withoutPermission($permissions)
 * @method static Builder<static>|User withoutRole($roles, ?string $guard = null)
 * @method static Builder<static>|User withoutTeam($teams)
 * @method static Builder<static>|User whereTimezone($value)
 *
 * @mixin \Eloquent
 */
final class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'timezone',
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Locale used for Fortify and other User-targeted notifications.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?? Config::string('app.locale');
    }

    /**
     * Timezone used to present instants to this user, including in queued mail
     * where there is no request and therefore no cookie.
     *
     * Reads the raw attribute rather than `$this->timezone`: the column is absent
     * on models that never selected it, and preventsAccessingMissingAttributes()
     * is enabled.
     */
    public function preferredTimezone(): string
    {
        $stored = $this->getAttributes()['timezone'] ?? null;

        return DisplayTimezones::sanitize(is_string($stored) ? $stored : null)
            ?? DisplayTimezones::default();
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * Linked SSO providers
     *
     * @return HasMany<OAuthConnection, $this>
     */
    public function oauthConnections(): HasMany
    {
        return $this->hasMany(OAuthConnection::class);
    }

    public function belongsToTenant(Tenant $tenant): bool
    {
        foreach ($this->tenantMemberships as $membership) {
            if ($membership->tenant_id === $tenant->getKey() && $membership->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this user is platform support staff (engineers, ops, etc.).
     */
    public function isPlatformSupport(): bool
    {
        // Avoid MissingAttributeException under Model::shouldBeStrict() when
        // the attribute was never hydrated onto this instance.
        if (! array_key_exists('platform_role', $this->getAttributes())) {
            return false;
        }

        return $this->platform_role === PlatformRole::Support;
    }

    /**
     * Whether this user has any platform-level access.
     */
    public function hasPlatformAccess(): bool
    {
        if (! array_key_exists('platform_role', $this->getAttributes())) {
            return false;
        }

        return $this->platform_role instanceof PlatformRole;
    }

    /**
     * Firm membership or an active read-only support session for this tenant.
     */
    public function canAccessTenant(Tenant $tenant): bool
    {
        if ($this->belongsToTenant($tenant)) {
            return true;
        }

        return $this->isPlatformSupport()
            && resolve(SupportSessionState::class)->isActiveFor($tenant);
    }

    /**
     * Whether this user may exercise a permission in a tenant.
     *
     * Active support sessions always use the read-only allow-list (even when
     * the user is also a firm member). Otherwise Spatie team roles apply.
     */
    public function hasTenantPermission(TenantPermission $permission, ?Tenant $tenant = null): bool
    {
        $tenant ??= Tenant::current();

        if (! $tenant instanceof Tenant || ! $this->canAccessTenant($tenant)) {
            return false;
        }

        $supportSession = resolve(SupportSessionState::class);

        if ($this->isPlatformSupport() && $supportSession->isActiveFor($tenant)) {
            return $supportSession->allowsPermission($permission);
        }

        if ($this->belongsToTenant($tenant)) {
            return $this->can($permission->value);
        }

        return false;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'platform_role' => PlatformRole::class,
        ];
    }
}
