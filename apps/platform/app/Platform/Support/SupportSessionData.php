<?php

declare(strict_types=1);

namespace App\Platform\Support;

use Illuminate\Support\Facades\Date;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonInterface;

use function is_int;
use function is_string;

/**
 * Immutable snapshot of an active platform support session.
 */
final readonly class SupportSessionData
{
    public function __construct(
        public int $tenantId,
        public string $reason,
        public CarbonInterface $startedAt,
        public CarbonInterface $expiresAt,
    ) {}

    /**
     * @param  array{tenant_id?: mixed, reason?: mixed, started_at?: mixed, expires_at?: mixed}  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $tenantId = $payload['tenant_id'] ?? null;
        $reason = $payload['reason'] ?? null;
        $startedAt = $payload['started_at'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;

        if (! is_int($tenantId) && ! ctype_digit((string) $tenantId)) {
            return null;
        }

        if (! is_string($reason) || mb_trim($reason) === '') {
            return null;
        }

        if (! is_string($startedAt) || ! is_string($expiresAt)) {
            return null;
        }

        return new self(
            tenantId: (int) $tenantId,
            reason: mb_trim($reason),
            startedAt: Date::parse($startedAt),
            expiresAt: Date::parse($expiresAt),
        );
    }

    public function hasExpired(?CarbonInterface $now = null): bool
    {
        return $this->expiresAt->lessThanOrEqualTo($now ?? now());
    }

    /**
     * @return array{tenant_id: int, reason: string, started_at: string, expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'reason' => $this->reason,
            'started_at' => $this->startedAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }

    public function isFor(Tenant $tenant): bool
    {
        return $this->tenantId === $tenant->getKey();
    }
}
