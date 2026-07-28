<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Accounts\Models\User;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;

use function in_array;
use function is_array;

/**
 * Session-backed, time-boxed read-only access to a single firm for platform support.
 * Does not create TenantMembership rows.
 */
final class SupportSession
{
    public const string SESSION_KEY = 'platform_support';

    /** Default support session length. */
    public const int DEFAULT_TTL_HOURS = 2;

    public function __construct(private readonly Session $session) {}

    /**
     * Permissions granted while in an active support session (read-only).
     *
     * @return list<Permission>
     */
    public static function readOnlyPermissions(): array
    {
        return [
            Permission::ViewDossiers,
            Permission::DownloadDocuments,
            Permission::ViewAuditLog,
        ];
    }

    /**
     * @return list<string>
     */
    public static function readOnlyPermissionValues(): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            self::readOnlyPermissions(),
        );
    }

    public function start(User $user, Tenant $tenant, string $reason, int $ttlHours = self::DEFAULT_TTL_HOURS): SupportSessionData
    {
        $reason = mb_trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A support session reason is required.');
        }

        if (! $user->isPlatformSupport()) {
            throw new InvalidArgumentException('Only platform support users can start a support session.');
        }

        $now = now();
        $data = new SupportSessionData(
            tenantId: $tenant->id,
            reason: $reason,
            startedAt: $now,
            expiresAt: $now->addHours($ttlHours),
        );

        $this->session->put(self::SESSION_KEY, $data->toArray());
        $this->session->put('active_tenant_id', $tenant->id);

        return $data;
    }

    public function end(): ?SupportSessionData
    {
        $active = $this->active(clearExpired: false);

        $this->session->forget(self::SESSION_KEY);

        return $active;
    }

    /**
     * Current non-expired support session, or null.
     * Expired sessions are cleared and returned as null when $clearExpired is true.
     */
    public function active(bool $clearExpired = true): ?SupportSessionData
    {
        $payload = $this->session->get(self::SESSION_KEY);

        if (! is_array($payload)) {
            return null;
        }

        /** @var array{tenant_id?: mixed, reason?: mixed, started_at?: mixed, expires_at?: mixed} $payload */
        $data = SupportSessionData::fromArray($payload);

        if (! $data instanceof SupportSessionData) {
            $this->session->forget(self::SESSION_KEY);

            return null;
        }

        if ($data->hasExpired()) {
            if ($clearExpired) {
                $this->session->forget(self::SESSION_KEY);
            }

            return null;
        }

        return $data;
    }

    /**
     * Peek at the stored session even if expired (for expiry audit logging).
     */
    public function peek(): ?SupportSessionData
    {
        $payload = $this->session->get(self::SESSION_KEY);

        if (! is_array($payload)) {
            return null;
        }

        /** @var array{tenant_id?: mixed, reason?: mixed, started_at?: mixed, expires_at?: mixed} $payload */
        return SupportSessionData::fromArray($payload);
    }

    public function isActiveFor(Tenant $tenant): bool
    {
        $active = $this->active();

        return $active instanceof SupportSessionData && $active->isFor($tenant);
    }

    public function allowsPermission(Permission|string $permission): bool
    {
        $value = $permission instanceof Permission ? $permission->value : $permission;

        return in_array($value, self::readOnlyPermissionValues(), true);
    }
}
