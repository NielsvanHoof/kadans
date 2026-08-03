<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Models\Activity;
use App\Audit\Policies\ActivityPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Audit domain authorization.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);
    }
}
