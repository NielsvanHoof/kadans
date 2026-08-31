<?php

declare(strict_types=1);

namespace App\Pipelines\Console\Commands;

use App\Pipelines\Actions\RetryPipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineRetryBackoff;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Laravel\Prompts\info;
use function sprintf;

/**
 * Re-queue failed runs whose backoff has elapsed.
 *
 * Goes through RetryPipelineAction rather than reimplementing the reset, so
 * automatic and manual retries cannot drift apart — the subject reset that both
 * depend on is the easy thing to forget. Passing no user marks the audit entry
 * as automatic.
 */
#[Signature('pipelines:retry-due')]
#[Description('Re-queue failed pipelines whose retry backoff has elapsed')]
final class RetryDuePipelinesCommand extends Command
{
    public function handle(
        TenantContext $tenantContext,
        RetryPipelineAction $retryPipeline,
        PipelineRetryBackoff $backoff,
    ): int {
        $due = Date::now();
        $batchSize = max(1, Config::integer('pipelines.retry.batch_size', 50));
        $retried = 0;
        $failures = 0;

        $tenantIds = Pipeline::queryWithoutTenantScope()
            ->toBase()
            ->where('status', PipelineStatus::Failed->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $due)
            ->where('attempt_number', '<', $backoff->maxAttempts())
            ->distinct()
            ->pluck('tenant_id');

        Tenant::query()->whereKey($tenantIds)->each(function (Tenant $tenant) use (
            $tenantContext,
            $retryPipeline,
            $backoff,
            $due,
            $batchSize,
            &$retried,
            &$failures,
        ): void {
            $tenantContext->runForTenant($tenant, function () use (
                $retryPipeline,
                $backoff,
                $due,
                $batchSize,
                &$retried,
                &$failures,
            ): void {
                $dueQuery = Pipeline::query();
                $dueQuery->getQuery()
                    ->where('status', PipelineStatus::Failed->value)
                    ->whereNotNull('next_retry_at')
                    ->where('next_retry_at', '<=', $due)
                    ->where('attempt_number', '<', $backoff->maxAttempts())
                    ->oldest('next_retry_at')
                    ->limit($batchSize);

                $dueQuery->get()->each(function (Pipeline $pipeline) use ($retryPipeline, &$retried, &$failures): void {
                    try {
                        $retryPipeline->handle($pipeline);
                        $retried++;
                    } catch (Throwable $exception) {
                        // One unretryable run must not stop the rest of the batch.
                        $failures++;
                        Log::warning('Pipelines: automatic retry skipped.', [
                            'pipeline_id' => $pipeline->id,
                            'tenant_id' => $pipeline->tenant_id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                });
            });
        });

        info(sprintf('Retried: %d. Skipped: %d.', $retried, $failures));

        return self::SUCCESS;
    }
}
