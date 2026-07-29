<?php

declare(strict_types=1);

namespace App\Accounts\Models;

use App\Accounts\Enums\PlatformRole;
use App\Platform\Support\SupportSession;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

use function array_key_exists;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $locale
 * @property PlatformRole|null $platform_role
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
#[UseFactory(UserFactory::class)]
final class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Locale used for Fortify and other User-targeted notifications.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?? (string) config('app.locale');
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
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
            && app(SupportSession::class)->isActiveFor($tenant);
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

        $supportSession = app(SupportSession::class);

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
