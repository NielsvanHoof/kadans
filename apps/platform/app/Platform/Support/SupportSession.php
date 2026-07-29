<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Accounts\Models\User;
use App\Audit\Enums\AuditPermission;
use App\DocumentProcessing\Enums\PipelinePermission;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Questionnaires\Enums\QuestionnaireTemplatePermission;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;

use function in_array;
use function is_array;

/**
 * Session-backed, time-boxed read-only access to a single firm for platform support.
 * Does not create TenantMembership rows.
 */
final readonly class SupportSession
{
    public const string SESSION_KEY = 'platform_support';

    /** Default support session length. */
    public const int DEFAULT_TTL_HOURS = 2;

    public function __construct(private Session $session) {}

    /**
     * Permissions granted while in an active support session (read-only).
     *
     * Every entry must be non-mutating: this list is the hard ceiling on what
     * platform support can do inside a customer's firm.
     *
     * @return list<TenantPermission>
     */
    public static function readOnlyPermissions(): array
    {
        return [
            DossierPermission::View,
            DocumentRequestPermission::View,
            UploadedDocumentPermission::View,
            UploadedDocumentPermission::Download,
            QuestionnaireTemplatePermission::View,
            PipelinePermission::View,
            AuditPermission::View,
        ];
    }

    /**
     * @return list<string>
     */
    public static function readOnlyPermissionValues(): array
    {
        return array_map(
            static fn (TenantPermission $permission): string => $permission->value,
            self::readOnlyPermissions(),
        );
    }

    public function start(User $user, Tenant $tenant, string $reason, int $ttlHours = self::DEFAULT_TTL_HOURS): SupportSessionData
    {
        $reason = mb_trim($reason);

        throw_if($reason === '', InvalidArgumentException::class, 'A support session reason is required.');

        throw_unless($user->isPlatformSupport(), InvalidArgumentException::class, 'Only platform support users can start a support session.');

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

    public function allowsPermission(TenantPermission|string $permission): bool
    {
        $value = $permission instanceof TenantPermission ? $permission->value : $permission;

        return in_array($value, self::readOnlyPermissionValues(), true);
    }
}
