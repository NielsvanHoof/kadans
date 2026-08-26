<?php

declare(strict_types=1);

namespace App\Platform\Console\Commands\Retention;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

use function is_string;
use function Laravel\Prompts\info;
use function sprintf;

/**
 * Chunked nightly sweep of idle agent conversations past the retention window.
 *
 * Deletes conversations, not messages: the composite FK cascades. Lock
 * duration tracks --limit rather than the backlog on a burstable RDS instance.
 */
#[Signature('retention:prune-agent-conversations {--limit=1000 : Maximum conversations to delete per batch}')]
#[Description('Delete idle agent conversations older than the configured retention period')]
final class PruneAgentConversationsCommand extends Command
{
    /**
     * Batches per tenant per run. 100 × the default --limit of 1000 is
     * 100_000 conversations, enough to drain a daily backlog. A tenant past
     * the cap is picked up again the next night.
     */
    private const int MAX_BATCHES_PER_TENANT = 100;

    public function handle(TenantContext $tenantContext): int
    {
        $cutoff = now()->subDays(Config::integer('retention.agent_conversation_days'));
        $limit = max(1, (int) $this->option('limit'));
        $deleted = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $cutoff, $limit, &$deleted): void {
            $tenantContext->runForTenant($tenant, function () use ($tenant, $cutoff, $limit, &$deleted): void {
                $deleted += $this->pruneTenant($tenant, $cutoff, $limit);
            });
        });

        info(sprintf(
            'Deleted %d agent conversation(s) last updated on or before %s.',
            $deleted,
            $cutoff->toDateString(),
        ));

        return self::SUCCESS;
    }

    private function pruneTenant(Tenant $tenant, CarbonInterface $cutoff, int $limit): int
    {
        $deleted = 0;
        $batches = 0;
        $table = $this->conversationsTable();

        // chunkById pages on id, so deleting the current chunk does not skip
        // the next one. Returning false from the callback stops the loop
        // once this tenant has used its batch budget.
        DB::table($table)
            ->where('tenant_id', $tenant->getKey())
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(
                $limit,
                function (Collection $conversations) use ($tenant, $table, &$deleted, &$batches): bool {
                    if ($conversations->isEmpty()) {
                        return false;
                    }

                    $deleted += DB::table($table)
                        ->where('tenant_id', $tenant->getKey())
                        ->whereIn('id', $conversations->pluck('id')->all())
                        ->delete();
                    $batches++;

                    return $batches < self::MAX_BATCHES_PER_TENANT;
                },
            );

        return $deleted;
    }

    private function conversationsTable(): string
    {
        $table = Config::get('ai.conversations.tables.conversations', 'agent_conversations');

        return is_string($table) ? $table : 'agent_conversations';
    }
}
