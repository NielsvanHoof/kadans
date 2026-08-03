<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Reporting\Support\ForgetsWorkspaceDashboardCache;
use App\Shared\Cache\TenantCacheInvalidator;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Reporting owns the tenant-scoped derived caches, so it binds the invalidator
 * that lower-layer models trigger through App\Shared.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // Stateless and derived from config only — safe as a singleton under Octane.
        $this->app->singleton(TenantCacheInvalidator::class, ForgetsWorkspaceDashboardCache::class);
    }
}
