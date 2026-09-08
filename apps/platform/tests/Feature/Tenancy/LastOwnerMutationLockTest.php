<?php

declare(strict_types=1);

use App\Tenancy\Actions\Members\RemoveTenantMemberAction;
use App\Tenancy\Actions\Members\SuspendTenantMemberAction;
use App\Tenancy\Actions\Members\UpdateTenantMemberRoleAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Support\Members\WorkspaceMemberRoles;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

covers(
    RemoveTenantMemberAction::class,
    SuspendTenantMemberAction::class,
    UpdateTenantMemberRoleAction::class,
);

test('remove, suspend, and demote lock the tenant row before counting owners', function (string $mutation): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $secondOwner = workspaceMember($tenant, Role::Owner);
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $secondOwner->id)
        ->firstOrFail();

    actingInWorkspace($tenant);

    $lockQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$lockQueries): void {
        $sql = mb_strtolower($query->sql);

        // Distinct from loadMissing('tenant'), which selects *.
        if (str_contains($sql, 'from "tenants"') && str_contains($sql, 'select "id"')) {
            $lockQueries[] = $query->sql;
        }
    });

    match ($mutation) {
        'remove' => resolve(RemoveTenantMemberAction::class)->handle($membership, $owner),
        'suspend' => resolve(SuspendTenantMemberAction::class)->handle($membership, $owner),
        'demote' => resolve(UpdateTenantMemberRoleAction::class)
            ->handle($membership, $owner, Role::Administrator->value),
    };

    expect($lockQueries)->not->toBeEmpty();

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(mb_strtolower($lockQueries[0]))->toContain('for update');
    }

    expect(resolve(WorkspaceMemberRoles::class)->activeOwnerCount($tenant))->toBe(1);
})->with([
    'remove' => 'remove',
    'suspend' => 'suspend',
    'demote' => 'demote',
]);
