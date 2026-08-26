<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Tenancy\Actions\AcceptTenantInvitationAction;
use App\Tenancy\Actions\InviteTenantMemberAction;
use App\Tenancy\Actions\RemoveTenantMemberAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Queries\FetchResponsibleStaffOptionsQuery;
use App\Tenancy\Support\StaffOptionsCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

covers(StaffOptionsCache::class);

test('a second staff-options load does not query users', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    resolve(FetchResponsibleStaffOptionsQuery::class)->handle($tenant);

    $userQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$userQueries): void {
        $sql = mb_strtolower($query->sql);

        if (str_contains($sql, 'from "users"') || str_contains($sql, 'from `users`')) {
            $userQueries[] = $query->sql;
        }
    });

    $options = resolve(FetchResponsibleStaffOptionsQuery::class)->handle($tenant);

    expect($userQueries)
        ->toBeEmpty()
        ->and($options)->toHaveCount(1)
        ->and(Cache::has(StaffOptionsCache::key($tenant->id)))->toBeTrue();
});

test('a second dossiers index render does not re-query staff options', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk();

    $staffOptionQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$staffOptionQueries): void {
        $sql = mb_strtolower($query->sql);

        if (
            (str_contains($sql, 'from "users"') || str_contains($sql, 'from `users`'))
            && str_contains($sql, 'limit 500')
        ) {
            $staffOptionQueries[] = $query->sql;
        }
    });

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk();

    expect($staffOptionQueries)
        ->toBeEmpty();
});

test('invite and remove forget the staff-options cache', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $query = resolve(FetchResponsibleStaffOptionsQuery::class);

    $query->handle($tenant);
    expect(Cache::has(StaffOptionsCache::key($tenant->id)))->toBeTrue();

    resolve(InviteTenantMemberAction::class)->handle(
        $tenant,
        $owner,
        'colleague@example.com',
        Role::Adviser->value,
    );

    expect(Cache::has(StaffOptionsCache::key($tenant->id)))->toBeFalse();

    $adviser = workspaceMember($tenant, Role::Adviser);
    $query->handle($tenant);
    expect(Cache::has(StaffOptionsCache::key($tenant->id)))->toBeTrue();

    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $adviser->id)
        ->firstOrFail();

    resolve(RemoveTenantMemberAction::class)->handle($membership, $owner);

    expect(Cache::has(StaffOptionsCache::key($tenant->id)))->toBeFalse();

    $afterRemove = $query->handle($tenant);

    expect(collect($afterRemove)->pluck('id')->all())->not->toContain($adviser->id);
});

test('accepting an invitation forgets the staff-options cache', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $invitee = User::factory()->create(['email' => 'join@example.com']);

    resolve(InviteTenantMemberAction::class)->handle(
        $tenant,
        $owner,
        $invitee->email,
        Role::Adviser->value,
    );

    $query = resolve(FetchResponsibleStaffOptionsQuery::class);
    $query->handle($tenant);
    expect(Cache::has(StaffOptionsCache::key($tenant->id)))->toBeTrue();

    $invitation = TenantInvitation::query()
        ->where('tenant_id', $tenant->id)
        ->where('email', $invitee->email)
        ->firstOrFail();

    resolve(AcceptTenantInvitationAction::class)->handle($invitation, $invitee);

    expect(Cache::has(StaffOptionsCache::key($tenant->id)))->toBeFalse();

    $afterAccept = $query->handle($tenant);

    expect(collect($afterAccept)->pluck('id')->all())->toContain($invitee->id);
});

test('recording last access does not forget staff options', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    resolve(FetchResponsibleStaffOptionsQuery::class)->handle($tenant);

    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    $membership->forceFill(['last_accessed_at' => now()])->save();

    expect(Cache::has(StaffOptionsCache::key($tenant->id)))->toBeTrue();
});
