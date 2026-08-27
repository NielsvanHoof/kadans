<?php

declare(strict_types=1);

namespace App\Platform\Console\Commands\Retention;

use App\Audit\Models\Activity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;

use function Laravel\Prompts\info;
use function sprintf;

/**
 * Chunked weekly sweep of activity_log rows past the retention window.
 *
 * Spatie's activitylog:clean issues one unchunked DELETE for every expired
 * row. That statement grows with the table — the fastest-growing one we
 * have — and holds locks for the whole sweep. This command deletes in
 * bounded batches per tenant so lock duration tracks --limit, not backlog.
 */
#[Signature('retention:prune-activity-log {--limit=1000 : Maximum rows to delete per batch}')]
#[Description('Delete activity log entries older than the configured retention period')]
final class PruneActivityLogCommand extends Command
{
    /**
     * Batches per tenant per run. 100 × the default --limit of 1000 is
     * 100_000 rows, enough to drain a weekly backlog without one giant
     * DELETE. A tenant past the cap is picked up again next week.
     */
    private const int MAX_BATCHES_PER_TENANT = 100;

    public function handle(TenantContext $tenantContext): int
    {
        $cutoff = now()->subDays(Config::integer('retention.activity_log_days'));
        $limit = max(1, (int) $this->option('limit'));
        $deleted = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $cutoff, $limit, &$deleted): void {
            $tenantContext->runForTenant($tenant, function () use ($tenant, $cutoff, $limit, &$deleted): void {
                $deleted += $this->pruneTenant($tenant, $cutoff, $limit);
            });
        });

        info(sprintf(
            'Deleted %d activity log row(s) created on or before %s.',
            $deleted,
            $cutoff->toDateString(),
        ));

        return self::SUCCESS;
    }

    private function pruneTenant(Tenant $tenant, CarbonInterface $cutoff, int $limit): int
    {
        $deleted = 0;
        $batches = 0;

        $query = Activity::queryForTenant($tenant);
        $query->getQuery()->where('created_at', '<=', $cutoff);

        // chunkById pages on id, so deleting the current chunk does not skip
        // the next one. Returning false from the callback stops the loop
        // once this tenant has used its batch budget.
        $query->oldest('id')->chunkById(
            $limit,
            function (Collection $activities) use ($tenant, &$deleted, &$batches): bool {
                /** @var Collection<int, Activity> $activities */
                if ($activities->isEmpty()) {
                    return false;
                }

                $activityQuery = Activity::queryForTenant($tenant);
                $activityQuery->getQuery()->whereIn('id', $activities->modelKeys());
                $deleted += $activityQuery->toBase()->delete();
                $batches++;

                return $batches < self::MAX_BATCHES_PER_TENANT;
            },
        );

        return $deleted;
    }
}
