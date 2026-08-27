<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Platform\Console\Commands\Retention\PruneActivityLogCommand;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

covers(PruneActivityLogCommand::class);

beforeEach(function (): void {
    config([
        'retention.activity_log_days' => 90,
    ]);
});

/**
 * @return list<string>
 */
function activityLogDeletes(callable $body): array
{
    /** @var list<string> $matched */
    $matched = [];

    DB::listen(function (QueryExecuted $query) use (&$matched): void {
        $sql = mb_strtolower($query->sql);

        if (str_starts_with($sql, 'delete') && str_contains($sql, 'activity_log')) {
            $matched[] = $query->sql;
        }
    });

    $body();

    return $matched;
}

function seedActivityRow(Tenant $tenant, int $daysAgo): Activity
{
    return Activity::query()->create([
        'log_name' => config('activitylog.default_log_name'),
        'description' => 'Seeded audit row',
        'event' => AuditEvent::DossierViewed->value,
        'tenant_id' => $tenant->id,
        'created_at' => now()->subDays($daysAgo),
        'updated_at' => now()->subDays($daysAgo),
    ]);
}

test('old activity log rows are pruned while recent rows remain', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $old = seedActivityRow($tenant, 100);
    $recent = seedActivityRow($tenant, 10);

    $this->artisan('retention:prune-activity-log')
        ->assertSuccessful();

    expect(Activity::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Activity::query()->whereKey($recent->id)->exists())->toBeTrue();
});

test('pruning is chunked rather than one unchunked delete', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    foreach (range(1, 5) as $days) {
        seedActivityRow($tenant, 90 + $days);
    }

    $recent = seedActivityRow($tenant, 10);

    $deletes = activityLogDeletes(function (): void {
        $this->artisan('retention:prune-activity-log', ['--limit' => 2])
            ->assertSuccessful();
    });

    // Five expired rows at --limit=2 is three DELETEs (2 + 2 + 1), not one.
    expect($deletes)->toHaveCount(3)
        ->and(Activity::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(Activity::queryForTenant($tenant)->where('created_at', '<=', now()->subDays(90))->exists())
        ->toBeFalse();
});

test('each tenant is pruned independently', function (): void {
    ['tenant' => $tenantA] = provisionWorkspace('Firm A');
    ['tenant' => $tenantB] = provisionWorkspace('Firm B');

    $oldA = seedActivityRow($tenantA, 100);
    $oldB = seedActivityRow($tenantB, 100);
    $recentB = seedActivityRow($tenantB, 5);

    $this->artisan('retention:prune-activity-log')->assertSuccessful();

    expect(Activity::query()->whereKey($oldA->id)->exists())->toBeFalse()
        ->and(Activity::query()->whereKey($oldB->id)->exists())->toBeFalse()
        ->and(Activity::query()->whereKey($recentB->id)->exists())->toBeTrue();
});
