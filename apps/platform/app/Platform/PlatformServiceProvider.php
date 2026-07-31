<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\PruneModelsCommand;
use App\Platform\Contracts\Production\ChecksReadiness;
use App\Platform\Services\Production\InfrastructureReadinessCheck;
use App\Platform\Services\Production\ProductionConfigurationValidator;
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

        $this->app->bind(ChecksReadiness::class, InfrastructureReadinessCheck::class);
    }

    public function boot(ProductionConfigurationValidator $productionConfigurationValidator): void
    {
        $productionConfigurationValidator->validate();
    }
}
