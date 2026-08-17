<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\PruneModelsCommand;
use App\Platform\Contracts\Production\ReadinessCheck;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Policies\DataSubjectRequestPolicy;
use App\Platform\Services\Production\InfrastructureReadinessCheck;
use App\Platform\Services\Production\ProductionConfigurationValidator;
use App\Platform\Support\PlatformSupportSessionState;
use App\Tenancy\Contracts\SupportSessionState;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Platform infrastructure bindings and production boot checks.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->commands([
            PruneModelsCommand::class,
        ]);

        $this->app->bind(ReadinessCheck::class, InfrastructureReadinessCheck::class);

        // Wraps the HTTP session, so it must not outlive a request under Octane.
        $this->app->scoped(SupportSessionState::class, PlatformSupportSessionState::class);
    }

    public function boot(ProductionConfigurationValidator $productionConfigurationValidator): void
    {
        Gate::policy(DataSubjectRequest::class, DataSubjectRequestPolicy::class);

        $productionConfigurationValidator->validate();
    }
}
