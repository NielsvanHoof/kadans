<?php

declare(strict_types=1);

use App\Platform\Console\Commands\Retention\PruneAgentConversationsCommand;
use App\Questionnaires\Ai\ChecklistDraftAgent;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

covers(PruneAgentConversationsCommand::class);

beforeEach(function (): void {
    config([
        'retention.agent_conversation_days' => 90,
    ]);
});

/**
 * @return list<string>
 */
function agentConversationDeletes(callable $body): array
{
    /** @var list<string> $matched */
    $matched = [];

    DB::listen(function (QueryExecuted $query) use (&$matched): void {
        $sql = mb_strtolower($query->sql);

        if (str_starts_with($sql, 'delete') && str_contains($sql, 'agent_conversations')) {
            $matched[] = $query->sql;
        }
    });

    $body();

    return $matched;
}

function seedAgentConversation(Tenant $tenant, int $daysAgo, bool $withMessage = false): string
{
    $id = (string) Str::uuid7();
    $at = now()->subDays($daysAgo);

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'tenant_id' => $tenant->id,
        'participant_type' => 'user',
        'participant_id' => 1,
        'title' => 'Seeded conversation',
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    if ($withMessage) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'conversation_id' => $id,
            'participant_type' => 'user',
            'participant_id' => 1,
            'agent' => ChecklistDraftAgent::class,
            'role' => 'user',
            'content' => 'Seeded message',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'approval_state' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    return $id;
}

test('old idle conversations are pruned while recent ones remain', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $old = seedAgentConversation($tenant, 100, withMessage: true);
    $recent = seedAgentConversation($tenant, 10, withMessage: true);

    $this->artisan('retention:prune-agent-conversations')
        ->assertSuccessful();

    expect(DB::table('agent_conversations')->where('id', $old)->exists())->toBeFalse()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $old)->exists())->toBeFalse()
        ->and(DB::table('agent_conversations')->where('id', $recent)->exists())->toBeTrue()
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $recent)->exists())->toBeTrue();
});

test('pruning is chunked rather than one unchunked delete', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    foreach (range(1, 5) as $days) {
        seedAgentConversation($tenant, 90 + $days);
    }

    $recent = seedAgentConversation($tenant, 10);

    $deletes = agentConversationDeletes(function (): void {
        $this->artisan('retention:prune-agent-conversations', ['--limit' => 2])
            ->assertSuccessful();
    });

    // Five expired rows at --limit=2 is three DELETEs (2 + 2 + 1), not one.
    expect($deletes)->toHaveCount(3)
        ->and(DB::table('agent_conversations')->where('id', $recent)->exists())->toBeTrue()
        ->and(DB::table('agent_conversations')
            ->where('tenant_id', $tenant->id)
            ->where('updated_at', '<=', now()->subDays(90))
            ->exists())->toBeFalse();
});

test('each tenant is pruned independently', function (): void {
    ['tenant' => $tenantA] = provisionWorkspace('Firm A');
    ['tenant' => $tenantB] = provisionWorkspace('Firm B');

    $oldA = seedAgentConversation($tenantA, 100);
    $oldB = seedAgentConversation($tenantB, 100);
    $recentB = seedAgentConversation($tenantB, 5);

    $this->artisan('retention:prune-agent-conversations')->assertSuccessful();

    expect(DB::table('agent_conversations')->where('id', $oldA)->exists())->toBeFalse()
        ->and(DB::table('agent_conversations')->where('id', $oldB)->exists())->toBeFalse()
        ->and(DB::table('agent_conversations')->where('id', $recentB)->exists())->toBeTrue();
});
