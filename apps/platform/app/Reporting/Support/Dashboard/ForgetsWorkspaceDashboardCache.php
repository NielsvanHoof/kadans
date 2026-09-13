<?php

declare(strict_types=1);

namespace App\Reporting\Support\Dashboard;

use App\Shared\Cache\TenantCacheInvalidator;

/**
 * Reporting's implementation of the shared tenant cache invalidation contract.
 *
 * Today the dashboard metrics cache is the only tenant-scoped derived cache; if
 * more appear, compose them here rather than adding traits to models.
 */
final readonly class ForgetsWorkspaceDashboardCache implements TenantCacheInvalidator
{
    public function forgetForTenant(int $tenantId): void
    {
        WorkspaceDashboardCache::forget($tenantId);
    }
}
