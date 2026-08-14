<?php

declare(strict_types=1);

use App\Tenancy\Http\Middleware\EnsureValidTenantMembership;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

covers(EnsureValidTenantMembership::class, TenantMembership::class);

/**
 * last_accessed_at used to be written on every workspace request — every page,
 * every Inertia partial reload, every deferred-prop XHR and every poll — as an
 * UPDATE touching two columns, since the model keeps timestamps. Nothing renders
 * the value at that resolution: the members list formats it as a date.
 */
function membershipUpdateCount(callable $body): int
{
    $updates = 0;

    DB::listen(function (QueryExecuted $query) use (&$updates): void {
        $sql = mb_strtolower($query->sql);

        if (str_starts_with($sql, 'update') && str_contains($sql, '"tenant_memberships"')) {
            $updates++;
        }
    });

    $body();

    return $updates;
}

test('a fresh last access is not rewritten on every request', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    // First request establishes the timestamp.
    $this->actingAs($owner)->get(workspaceRoute('workspaces.dashboard', $tenant))->assertOk();

    $updates = membershipUpdateCount(function () use ($owner, $tenant): void {
        // Time has to move between requests, or the timestamp is identical,
        // the model is not dirty and Eloquent issues no UPDATE anyway — which
        // would make this assertion pass with or without the throttle. Sixty
        // seconds apart, still inside the five-minute window.
        foreach (range(1, 3) as $ignored) {
            $this->travel(60)->seconds();
            $this->actingAs($owner)->get(workspaceRoute('workspaces.dashboard', $tenant))->assertOk();
        }
    });

    expect($updates)->toBe(0);
});

test('a stale last access is written again', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)->get(workspaceRoute('workspaces.dashboard', $tenant))->assertOk();

    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $owner->id)
        ->sole();

    $before = $membership->last_accessed_at;

    expect($before)->not->toBeNull();

    // Past the throttle window, so the next request must record access again.
    $this->travel(TenantMembership::ACCESS_RECORD_THROTTLE_SECONDS + 60)->seconds();

    $updates = membershipUpdateCount(function () use ($owner, $tenant): void {
        $this->actingAs($owner)->get(workspaceRoute('workspaces.dashboard', $tenant))->assertOk();
    });

    expect($updates)->toBe(1)
        ->and($membership->refresh()->last_accessed_at?->gt($before))->toBeTrue();
});

test('a membership that has never recorded access is written immediately', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $owner->id)
        ->update(['last_accessed_at' => null]);

    $this->actingAs($owner)->get(workspaceRoute('workspaces.dashboard', $tenant))->assertOk();

    expect(TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $owner->id)
        ->value('last_accessed_at'))->not->toBeNull();
});

test('hasRecentAccessRecorded reports staleness against the throttle window', function (): void {
    $throttle = TenantMembership::ACCESS_RECORD_THROTTLE_SECONDS;

    expect(new TenantMembership(['last_accessed_at' => null])->hasRecentAccessRecorded())->toBeFalse()
        ->and(new TenantMembership(['last_accessed_at' => now()])->hasRecentAccessRecorded())->toBeTrue()
        ->and(new TenantMembership(['last_accessed_at' => now()->subSeconds($throttle - 30)])->hasRecentAccessRecorded())->toBeTrue()
        // Exactly at the boundary counts as stale, so the value can never sit
        // older than the window without being refreshed.
        ->and(new TenantMembership(['last_accessed_at' => now()->subSeconds($throttle)])->hasRecentAccessRecorded())->toBeFalse()
        ->and(new TenantMembership(['last_accessed_at' => now()->subSeconds($throttle + 30)])->hasRecentAccessRecorded())->toBeFalse();
});
