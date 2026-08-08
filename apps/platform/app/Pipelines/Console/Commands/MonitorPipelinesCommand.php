<?php

declare(strict_types=1);

namespace App\Pipelines\Console\Commands;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;

use function Laravel\Prompts\info;
use function sprintf;

#[Signature('pipelines:monitor {--stuck-after=30 : Minutes without activity before a running pipeline is marked stuck} {--repeat-after=2 : Failed attempts considered repeatedly failing}')]
#[Description('Report and mark inactive application pipelines')]
final class MonitorPipelinesCommand extends Command
{
    public function handle(
        TenantContext $tenantContext,
        PipelineState $pipelineState,
    ): int {
        $stuckAfter = max(1, (int) $this->option('stuck-after'));
        $repeatAfter = max(1, (int) $this->option('repeat-after'));
        $cutoff = Date::now()->subMinutes($stuckAfter);
        $active = 0;
        $markedStuck = 0;
        $repeatedlyFailing = 0;

        Tenant::query()->each(function (Tenant $tenant) use (
            $tenantContext,
            $pipelineState,
            $cutoff,
            $repeatAfter,
            &$active,
            &$markedStuck,
            &$repeatedlyFailing,
        ): void {
            $tenantContext->runForTenant($tenant, function () use (
                $pipelineState,
                $cutoff,
                $repeatAfter,
                &$active,
                &$markedStuck,
                &$repeatedlyFailing,
            ): void {
                $activeQuery = Pipeline::query();
                $activeQuery->getQuery()->whereIn('status', [PipelineStatus::Pending->value, PipelineStatus::Running->value]);
                // toBase() applies global scopes before dropping to the query
                // builder; getQuery() does not, and would report every tenant's
                // total once per tenant.
                $active += $activeQuery->toBase()->count();

                $stuckQuery = Pipeline::query();
                $stuckQuery->getQuery()
                    ->where('status', PipelineStatus::Running->value)
                    ->where(function (Builder $query) use ($cutoff): void {
                        $query
                            ->where('last_activity_at', '<=', $cutoff)
                            ->orWhere(function (Builder $query) use ($cutoff): void {
                                $query
                                    ->whereNull('last_activity_at')
                                    ->where('started_at', '<=', $cutoff);
                            });
                    });

                $stuckQuery->get()->each(function (Pipeline $pipeline) use ($pipelineState, &$markedStuck): void {
                    $pipelineState->stuck(
                        $pipeline,
                        $pipeline->current_stage,
                        errorCode: 'pipeline_inactive',
                        errorMessage: 'No activity was recorded within the monitoring window.',
                    );
                    $markedStuck++;
                });

                $failedQuery = Pipeline::query();
                $failedQuery->getQuery()
                    ->where('status', PipelineStatus::Failed->value)
                    ->where('attempt_number', '>=', $repeatAfter);
                $repeatedlyFailing += $failedQuery->toBase()->count();
            });
        });

        info(sprintf(
            'Active: %d. Marked stuck: %d. Repeatedly failing: %d.',
            $active,
            $markedStuck,
            $repeatedlyFailing,
        ));

        return self::SUCCESS;
    }
}
