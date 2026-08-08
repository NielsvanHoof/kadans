<?php

declare(strict_types=1);

namespace App\Pipelines;

use App\Pipelines\Models\Pipeline;
use App\Pipelines\Policies\PipelinePolicy;
use App\Pipelines\Support\PipelineRetryRunnerRegistry;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

final class PipelinesServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(PipelineRetryRunnerRegistry::class);
        $this->app->singleton(PipelineSubjectPresenterRegistry::class);
    }

    public function boot(): void
    {
        Gate::policy(Pipeline::class, PipelinePolicy::class);
    }
}
