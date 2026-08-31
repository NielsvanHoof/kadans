<?php

declare(strict_types=1);

namespace App\Pipelines\Console\Commands;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

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

        // Cross-tenant aggregates — no need to enter idle firms.
        $activeQuery = Pipeline::queryWithoutTenantScope();
        $activeQuery->getQuery()->whereIn(
            'status',
            [PipelineStatus::Pending->value, PipelineStatus::Running->value],
        );
        $active = $activeQuery->toBase()->count();

        $failedQuery = Pipeline::queryWithoutTenantScope();
        $failedQuery->getQuery()
            ->where('status', PipelineStatus::Failed->value)
            ->where('attempt_number', '>=', $repeatAfter);
        $repeatedlyFailing = $failedQuery->toBase()->count();

        $markedStuck = 0;

        $this->stuckPipelinesByTenant($cutoff)->each(
            function (Collection $pipelines, int|string $tenantId) use (
                $tenantContext,
                $pipelineState,
                &$markedStuck,
            ): void {
                $tenant = Tenant::query()->find($tenantId);

                if (! $tenant instanceof Tenant) {
                    return;
                }

                $tenantContext->runForTenant($tenant, function () use (
                    $pipelines,
                    $pipelineState,
                    &$markedStuck,
                ): void {
                    foreach ($pipelines as $pipeline) {
                        $scoped = Pipeline::query()->find($pipeline->getKey());

                        if (! $scoped instanceof Pipeline) {
                            continue;
                        }

                        $pipelineState->stuck(
                            $scoped,
                            $scoped->current_stage,
                            errorCode: 'pipeline_inactive',
                            errorMessage: 'No activity was recorded within the monitoring window.',
                        );
                        $markedStuck++;
                    }
                });
            },
        );

        info(sprintf(
            'Active: %d. Marked stuck: %d. Repeatedly failing: %d.',
            $active,
            $markedStuck,
            $repeatedlyFailing,
        ));

        // Console output goes nowhere in a container. Anything actionable has to
        // reach the log stream, where StuckPipelinesCheck also raises an alert.
        $context = [
            'active' => $active,
            'marked_stuck' => $markedStuck,
            'repeatedly_failing' => $repeatedlyFailing,
            'stuck_after_minutes' => $stuckAfter,
        ];

        if ($markedStuck > 0 || $repeatedlyFailing > 0) {
            Log::warning('Pipelines: monitor found pipelines needing attention.', $context);
        } else {
            Log::info('Pipelines: monitor found no stalled pipelines.', $context);
        }

        return self::SUCCESS;
    }

    /**
     * Running pipelines past the inactivity cutoff, keyed by tenant.
     *
     * @return Collection<int|string, Collection<int, Pipeline>>
     */
    private function stuckPipelinesByTenant(DateTimeInterface $cutoff): Collection
    {
        $stuckQuery = Pipeline::queryWithoutTenantScope();
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

        return $stuckQuery->get()->groupBy('tenant_id');
    }
}
