<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Tenancy\Actions\CreateTenantRoleAction;
use App\Tenancy\Actions\RegisterAndAcceptTenantInvitationAction;
use App\Tenancy\Actions\UpdateTenantRoleAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

test('register-and-accept rolls back the user when membership creation fails', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.members.store', $tenant), [
            'email' => 'rollback@example.com',
            'role' => Role::Adviser->value,
        ])
        ->assertRedirect();

    $invitation = TenantInvitation::query()
        ->where('tenant_id', $tenant->id)
        ->where('email', 'rollback@example.com')
        ->firstOrFail();

    auth()->logout();

    $event = 'eloquent.creating: '.TenantMembership::class;
    Event::listen($event, static function (): void {
        throw new RuntimeException('forced membership failure');
    });

    try {
        expect(fn () => resolve(RegisterAndAcceptTenantInvitationAction::class)->handle($invitation, [
            'name' => 'Rollback User',
            'email' => 'rollback@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))->toThrow(RuntimeException::class, 'forced membership failure');
    } finally {
        Event::forget($event);
    }

    expect(User::query()->where('email', 'rollback@example.com')->exists())->toBeFalse()
        ->and(TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('user', fn (Builder $query) => $query->where('email', 'rollback@example.com'))
            ->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull()
        ->and(Auth::check())->toBeFalse();
});

test('create tenant role rolls back when permission sync fails mid-write', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $event = 'eloquent.created: '.TenantRole::class;
    Event::listen($event, static function (): void {
        throw new RuntimeException('forced role create failure');
    });

    try {
        expect(fn () => resolve(CreateTenantRoleAction::class)->handle(
            $tenant,
            $owner,
            'atomic-role',
            [WorkspacePermission::ViewMembers->value],
        ))->toThrow(RuntimeException::class, 'forced role create failure');
    } finally {
        Event::forget($event);
    }

    expect(TenantRole::query()
        ->where('tenant_id', $tenant->id)
        ->where('name', 'atomic-role')
        ->exists())->toBeFalse();
});

test('update tenant role rolls back when a mid-update failure occurs', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $role = resolve(CreateTenantRoleAction::class)->handle(
        $tenant,
        $owner,
        'stable-role',
        [WorkspacePermission::ViewMembers->value],
    );

    $event = 'eloquent.updated: '.TenantRole::class;
    Event::listen($event, static function (): void {
        throw new RuntimeException('forced role update failure');
    });

    try {
        expect(fn () => resolve(UpdateTenantRoleAction::class)->handle(
            $role,
            $owner,
            'renamed-role',
            [WorkspacePermission::InviteMembers->value],
        ))->toThrow(RuntimeException::class, 'forced role update failure');
    } finally {
        Event::forget($event);
    }

    $role->refresh();

    expect($role->name)->toBe('stable-role')
        ->and($role->hasPermissionTo(WorkspacePermission::ViewMembers->value))->toBeTrue()
        ->and($role->hasPermissionTo(WorkspacePermission::InviteMembers->value))->toBeFalse();
});
