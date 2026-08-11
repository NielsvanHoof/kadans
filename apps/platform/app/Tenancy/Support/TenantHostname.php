<?php

declare(strict_types=1);

namespace App\Tenancy\Support;

use App\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

use function in_array;
use function is_string;

/**
 * Hostname helpers for central vs {slug}.{base} tenant hosts.
 *
 * Bound as a scoped service — config is snapshotted at resolve time so Octane
 * workers do not keep stale env across config:clear without a restart.
 */
final readonly class TenantHostname
{
    /**
     * @param  list<string>  $centralDomains
     * @param  list<string>  $reservedSubdomains
     */
    public function __construct(
        private string $baseDomain,
        private array $centralDomains,
        private array $reservedSubdomains,
    ) {}

    public function baseDomain(): string
    {
        return $this->baseDomain;
    }

    /**
     * @return list<string>
     */
    public function centralDomains(): array
    {
        return $this->centralDomains;
    }

    /**
     * @return list<string>
     */
    public function reservedSubdomains(): array
    {
        return $this->reservedSubdomains;
    }

    public function isCentralHost(string $host): bool
    {
        return in_array(mb_strtolower($host), $this->centralDomains, true);
    }

    public function isReservedSubdomain(string $label): bool
    {
        return in_array(mb_strtolower($label), $this->reservedSubdomains, true);
    }

    /**
     * DNS-label rules for tenant slugs used as subdomains.
     */
    public function isValidTenantSlug(string $slug): bool
    {
        if ($slug === '' || mb_strlen($slug) > 63) {
            return false;
        }

        if ($this->isReservedSubdomain($slug)) {
            return false;
        }

        // RFC 1123 label: alphanumerics and hyphens, not starting/ending with hyphen.
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug);
    }

    public function hostForTenant(Tenant|string $tenant): string
    {
        $slug = $tenant instanceof Tenant ? $tenant->slug : $tenant;

        return $slug.'.'.$this->baseDomain;
    }

    /**
     * First label of a tenant host, or null on central / malformed hosts.
     */
    public function tenantSlugFromHost(string $host): ?string
    {
        $host = mb_strtolower($host);

        if ($this->isCentralHost($host) || $this->baseDomain === '') {
            return null;
        }

        $suffix = '.'.$this->baseDomain;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = mb_substr($host, 0, -mb_strlen($suffix));

        if ($slug === '' || str_contains($slug, '.')) {
            return null;
        }

        if (! $this->isValidTenantSlug($slug)) {
            return null;
        }

        return $slug;
    }

    public function tenantSlugFromRequest(Request $request): ?string
    {
        $host = $request->getHost();

        return is_string($host) ? $this->tenantSlugFromHost($host) : null;
    }

    /**
     * Parent cookie domain (with leading dot) for sharing sessions across
     * app.{base} and {slug}.{base}.
     */
    public function parentSessionDomain(): string
    {
        return '.'.$this->baseDomain;
    }
}
