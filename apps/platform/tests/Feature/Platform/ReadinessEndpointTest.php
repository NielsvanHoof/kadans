<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Platform\Contracts\Production\ChecksReadiness;
use App\Tenancy\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;
use Spatie\Health\Jobs\HealthQueueJob;

test('readiness succeeds when production dependencies are available', function (): void {
    app()->bind(ChecksReadiness::class, fn (): ChecksReadiness => new class implements ChecksReadiness
    {
        public function check(): void {}
    });

    $this->getJson('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ready']);
});

test('readiness fails closed when a production dependency is unavailable', function (): void {
    app()->bind(ChecksReadiness::class, fn (): ChecksReadiness => new class implements ChecksReadiness
    {
        public function check(): void
        {
            throw new RuntimeException('Sensitive connection failure.');
        }
    });

    $this->getJson('/ready')
        ->assertServiceUnavailable()
        ->assertExactJson(['status' => 'unavailable']);
});

test('liveness does not depend on production dependencies', function (): void {
    app()->bind(ChecksReadiness::class, fn (): ChecksReadiness => new class implements ChecksReadiness
    {
        public function check(): void
        {
            throw new RuntimeException('Dependency unavailable.');
        }
    });

    $this->get('/up')->assertOk();
});

test('liveness and readiness respond without a global host allowance', function (): void {
    // production.allowed_hosts restricted to the app host only (no blanket
    // IPv4 pattern) — health/readiness never call getHost(), so the load
    // balancer's Host header never needs a special allowance.
    config(['production.allowed_hosts' => ['^app\.example\.com$']]);

    app()->bind(ChecksReadiness::class, fn (): ChecksReadiness => new class implements ChecksReadiness
    {
        public function check(): void {}
    });

    $this->withHeaders(['Host' => '203.0.113.10'])->get('/up')->assertOk();
    $this->withHeaders(['Host' => '203.0.113.10'])->getJson('/ready')->assertOk();
});

test('operational health registers infrastructure and process heartbeat checks', function (): void {
    $registeredChecks = Health::registeredChecks();

    expect($registeredChecks->contains(fn ($check): bool => $check instanceof DatabaseCheck))->toBeTrue()
        ->and($registeredChecks->contains(fn ($check): bool => $check instanceof RedisCheck))->toBeTrue()
        ->and($registeredChecks->contains(fn ($check): bool => $check instanceof QueueCheck))->toBeTrue()
        ->and($registeredChecks->contains(fn ($check): bool => $check instanceof ScheduleCheck))->toBeTrue();
});

test('operational health details require authentication', function (): void {
    $this->getJson('/health')->assertUnauthorized();
});

test('a verified user who is not platform support cannot read health details', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/health')
        ->assertForbidden();
});

test('a platform support user can read health details', function (): void {
    $support = User::factory()->platformSupport()->create();

    // Spatie returns 503 when any stored check is failing; that is the
    // diagnostics payload itself, not an auth failure. Authorization is
    // what this test guards — platform support must receive the JSON body.
    $response = $this->actingAs($support)->getJson('/health');

    expect($response->status())->toBeIn([200, 503]);
    $response->assertJsonStructure(['finishedAt', 'checkResults']);
});

test('queue schedule and health heartbeats are scheduled every minute', function (): void {
    $commands = collect(resolve(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->implode(' ');

    expect($commands)
        ->toContain('health:queue-check-heartbeat')
        ->toContain('health:schedule-check-heartbeat')
        ->toContain('health:check');
});

test('retention and pruning tasks are scheduled', function (): void {
    $commands = collect(resolve(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->implode(' ');

    expect($commands)
        ->toContain('queue:prune-failed')
        ->toContain('queue:prune-batches')
        ->toContain('model:prune')
        ->toContain('retention:prune-notifications')
        ->toContain('retention:purge-legal')
        ->toContain('activitylog:clean');
});

// Scheduler-dispatched queue heartbeats have no tenant; they must not be tenant-aware.
test('health queue heartbeat job is not tenant aware', function (): void {
    expect(config('multitenancy.not_tenant_aware_jobs'))
        ->toContain(HealthQueueJob::class);
});

// Sync queue processes HealthQueueJob immediately; this used to throw CurrentTenantCouldNotBeDeterminedInTenantAwareJob.
test('health queue heartbeat succeeds without a current tenant', function (): void {
    Tenant::forgetCurrent();

    $this->artisan('health:queue-check-heartbeat')->assertSuccessful();
});
