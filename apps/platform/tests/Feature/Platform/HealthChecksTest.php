<?php

declare(strict_types=1);

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Models\Pipeline;
use App\Platform\Services\Health\FailedQueueJobsCheck;
use App\Platform\Services\Health\StuckPipelinesCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;
// Spatie's Status is a spatie/enum class exposing ok()/failed() as static
// *methods* via __callStatic — there are no Status::OK / Status::FAILED
// constants, and referencing them is a fatal "undefined constant".
use Spatie\Health\ResultStores\CacheHealthResultStore;
use Spatie\Health\ResultStores\InMemoryHealthResultStore;

/**
 * @param  array<string, mixed>  $attributes
 */
function insertFailedJob(array $attributes = []): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => Str::uuid()->toString(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: Textract returned no blocks.',
        'failed_at' => now(),
        ...$attributes,
    ]);
}

test('the registered checks cover work finishing, not just workers running', function (): void {
    $registered = array_map(
        static fn (object $check): string => $check::class,
        Health::registeredChecks()->all(),
    );

    expect($registered)->toContain(FailedQueueJobsCheck::class)
        ->and($registered)->toContain(StuckPipelinesCheck::class)
        ->and($registered)->toContain(HorizonCheck::class);
});

test('the failed job check passes when nothing failed recently', function (): void {
    $result = FailedQueueJobsCheck::new()->within(60)->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->meta['failed_jobs'])->toBe(0);
});

test('the failed job check fails when a job exhausted its retries', function (): void {
    insertFailedJob();

    $result = FailedQueueJobsCheck::new()->within(60)->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->meta['failed_jobs'])->toBe(1);
});

/**
 * Otherwise a single historic failure would keep the check red forever and the
 * alert would be trained away.
 */
test('the failed job check ignores failures outside the window', function (): void {
    insertFailedJob(['failed_at' => now()->subHours(3)]);

    $result = FailedQueueJobsCheck::new()->within(60)->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->meta['failed_jobs'])->toBe(0);
});

test('the stuck pipeline check passes when every pipeline is progressing', function (): void {
    provisionWorkspace();

    Pipeline::factory()->create(['status' => PipelineStatus::Running]);

    expect(StuckPipelinesCheck::new()->run()->status)->toBe(Status::ok());
});

/**
 * Counts across every tenant: this is an operator-facing check, and a stuck
 * pipeline in one firm must not be hidden by whichever tenant happens to be
 * current when the scheduler runs.
 */
test('the stuck pipeline check sees stuck pipelines in any tenant', function (): void {
    ['tenant' => $first] = provisionWorkspace('First Firm');
    Pipeline::factory()->create(['status' => PipelineStatus::Stuck]);

    ['tenant' => $second] = provisionWorkspace('Second Firm');
    Pipeline::factory()->create(['status' => PipelineStatus::Stuck]);

    expect($first->id)->not->toBe($second->id);

    $result = StuckPipelinesCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->meta['stuck_pipelines'])->toBe(2);
});

test('health notifications are on by default in production and off locally', function (): void {
    // HEALTH_NOTIFICATIONS_ENABLED must be absent for the environment default to apply.
    $read = static fn (string $environment): bool => (bool) configFileWithEnv('health', [
        'APP_ENV' => $environment,
        'HEALTH_NOTIFICATIONS_ENABLED' => null,
    ])['notifications']['enabled'];

    expect($read('production'))->toBeTrue()
        ->and($read('local'))->toBeFalse();
});

/**
 * The scheduler that writes health results and the web instance that serves
 * /health are different containers, and on a horizontally scaled platform there
 * may be several of each. A store that only one instance can read would leave
 * /health reporting whatever the instance answering the request happened to
 * know, which is usually nothing.
 *
 * In-memory is always instance-local. The cache store is only as shared as the
 * cache driver behind it, so `file` and `array` are out while `redis` is fine —
 * checking the class alone would either miss the bad case or ban a good one.
 */
test('health results are stored somewhere every instance can read', function (): void {
    /** @var array<class-string, array<string, mixed>> $stores */
    $stores = config('health.result_stores');

    expect($stores)->not->toBeEmpty();

    foreach ($stores as $store => $options) {
        expect($store)->not->toBe(InMemoryHealthResultStore::class);

        if ($store !== CacheHealthResultStore::class) {
            continue;
        }

        $driver = config('cache.stores.'.$options['store'].'.driver');

        expect($driver)->not->toBeIn(['file', 'array']);
    }
});
