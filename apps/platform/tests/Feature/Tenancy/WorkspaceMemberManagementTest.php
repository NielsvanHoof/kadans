<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Notifications\WorkspaceMemberInviteNotification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

pest()
    ->use(RefreshDatabase::class);

test('owners can view the members page', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.members.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/members/index')
            ->has('members', 1)
            ->has('invitations', 0)
            ->has('role_options')
            // Owners can assign the owner role; the frontend wires this prop.
            ->where('can_assign_owner', true)
            // The sole owner is marked as the current user so Remove stays hidden.
            ->where('members.0.is_current_user', true)
            ->where('members.0.role', Role::Owner->value));
});

test('members page does not re-query roles already loaded by authorization', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    workspaceMember($tenant, Role::Adviser);
    workspaceMember($tenant, Role::Reviewer);

    $rolePivotQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$rolePivotQueries): void {
        // Spatie loads user roles through model_has_roles; count only those.
        if (str_contains($query->sql, 'model_has_roles')) {
            $rolePivotQueries[] = $query->sql;
        }
    });

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.members.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/members/index')
            ->has('members', 3));

    // One query from Gate/hasRole on the viewer, plus one batch for the other
    // members — not one getRoleNames() per membership row.
    expect($rolePivotQueries)->toHaveCount(2);
});

test('administrators see can_assign_owner as false on the members page', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    $this->actingAs($admin)
        ->get(workspaceRoute('workspaces.members.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/members/index')
            ->where('can_assign_owner', false));
});

test('read-only members cannot manage workspace members', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);

    $this->actingAs($reader)
        ->get(workspaceRoute('workspaces.members.index', $tenant))
        ->assertForbidden();

    $this->actingAs($reader)
        ->post(workspaceRoute('workspaces.members.store', $tenant), [
            'email' => 'colleague@example.com',
            'role' => Role::Adviser->value,
        ])
        ->assertForbidden();
});

test('owners can invite a member and send a notification', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.members.store', $tenant), [
            'email' => 'colleague@example.com',
            'role' => Role::Adviser->value,
        ])
        ->assertRedirect(workspaceRoute('workspaces.members.index', $tenant));

    $invitation = TenantInvitation::query()
        ->where('email', 'colleague@example.com')
        ->firstOrFail();

    expect($invitation->role)->toBe(Role::Adviser->value)
        ->and($invitation->isPending())->toBeTrue();

    Notification::assertSentOnDemand(WorkspaceMemberInviteNotification::class);

    expect(Activity::query()
        ->where('event', AuditEvent::MemberInvited->value)
        ->exists())->toBeTrue();
});

test('administrators can invite but cannot assign the owner role', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    $this->actingAs($admin)
        ->post(workspaceRoute('workspaces.members.store', $tenant), [
            'email' => 'boss@example.com',
            'role' => Role::Owner->value,
        ])
        ->assertSessionHasErrors('role');
});

test('an existing user can accept an invitation', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $invitee = User::factory()->create(['email' => 'colleague@example.com']);

    $response = $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.members.store', $tenant), [
            'email' => $invitee->email,
            'role' => Role::Reviewer->value,
        ]);

    $response->assertRedirect();

    $invitation = TenantInvitation::query()
        ->where('email', $invitee->email)
        ->firstOrFail();

    // Recover the plain token from the notification accept URL.
    Notification::assertSentOnDemand(
        WorkspaceMemberInviteNotification::class,
        function (WorkspaceMemberInviteNotification $notification) use (&$token): bool {
            $token = basename(parse_url($notification->acceptUrl, PHP_URL_PATH) ?: '');

            return $token !== '';
        },
    );

    expect($token ?? null)->not->toBeEmpty();

    $this->actingAs($invitee)
        ->post(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $tenant));

    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $invitee->id)
        ->firstOrFail();

    expect($membership->status)->toBe(TenantMembershipStatus::Active);

    actingInWorkspace($tenant);
    expect($invitee->fresh()->hasRole(Role::Reviewer->value))->toBeTrue()
        ->and(Activity::query()
            ->where('event', AuditEvent::MemberInvitationAccepted->value)
            ->exists())
        ->toBeTrue();
});

test('a guest can register from an invitation', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.members.store', $tenant), [
            'email' => 'newhire@example.com',
            'role' => Role::Adviser->value,
        ])
        ->assertRedirect();

    $token = null;

    Notification::assertSentOnDemand(
        WorkspaceMemberInviteNotification::class,
        function (WorkspaceMemberInviteNotification $notification) use (&$token): bool {
            $token = basename(parse_url($notification->acceptUrl, PHP_URL_PATH) ?: '');

            return true;
        },
    );

    auth()->logout();

    $this->post(route('invitations.register', ['token' => $token]), [
        'name' => 'New Hire',
        'email' => 'newhire@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(workspaceRoute('workspaces.dashboard', $tenant));

    $user = User::query()->where('email', 'newhire@example.com')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', TenantMembershipStatus::Active->value)
            ->exists())->toBeTrue();

    actingInWorkspace($tenant);
    expect($user->fresh()->hasRole(Role::Adviser->value))->toBeTrue();
});

test('owners can change a member role and it is audited', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, Role::Adviser);
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.members.update', $tenant, [
            'membership' => $membership,
        ]), [
            'role' => Role::Reviewer->value,
        ])
        ->assertRedirect(workspaceRoute('workspaces.members.index', $tenant));

    actingInWorkspace($tenant);
    expect($member->fresh()->hasRole(Role::Reviewer->value))->toBeTrue()
        ->and($member->fresh()->hasRole(Role::Adviser->value))->toBeFalse()
        ->and(Activity::query()
            ->where('event', AuditEvent::MemberRoleChanged->value)
            ->exists())
        ->toBeTrue();
});

test('the last owner cannot be demoted or removed', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.members.update', $tenant, [
            'membership' => $membership,
        ]), [
            'role' => Role::Administrator->value,
        ])
        ->assertSessionHasErrors('role');

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.members.destroy', $tenant, [
            'membership' => $membership,
        ]))
        ->assertForbidden();
});

test('owners can suspend and remove another member', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, Role::Adviser);
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.members.update', $tenant, [
            'membership' => $membership,
        ]), [
            'status' => TenantMembershipStatus::Suspended->value,
        ])
        ->assertRedirect(workspaceRoute('workspaces.members.index', $tenant));

    expect($membership->fresh()->status)->toBe(TenantMembershipStatus::Suspended);

    $this->actingAs($member)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertNotFound();

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.members.destroy', $tenant, [
            'membership' => $membership->id,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.members.index', $tenant));

    expect(TenantMembership::query()->whereKey($membership->id)->exists())->toBeFalse()
        ->and(Activity::query()
            ->where('event', AuditEvent::MemberSuspended->value)
            ->exists())
        ->toBeTrue()
        ->and(Activity::query()
            ->where('event', AuditEvent::MemberRemoved->value)
            ->exists())
        ->toBeTrue();
});

test('invitations can be resent and revoked', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.members.store', $tenant), [
            'email' => 'pending@example.com',
            'role' => Role::ReadOnly->value,
        ]);

    $invitation = TenantInvitation::query()
        ->where('email', 'pending@example.com')
        ->firstOrFail();

    Notification::fake();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.members.invitations.resend', $tenant, [
            'invitation' => $invitation,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.members.index', $tenant));

    Notification::assertSentOnDemand(WorkspaceMemberInviteNotification::class);

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.members.invitations.destroy', $tenant, [
            'invitation' => $invitation,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.members.index', $tenant));

    expect($invitation->fresh()->isRevoked())->toBeTrue()
        ->and(Activity::query()
            ->where('event', AuditEvent::MemberInvitationRevoked->value)
            ->exists())
        ->toBeTrue();
});

test('members cannot manage another tenant workspace', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Firm A');
    ['owner' => $ownerB, 'tenant' => $tenantB] = provisionWorkspace('Firm B');

    $this->actingAs($ownerA)
        ->post(workspaceRoute('workspaces.members.store', $tenantB), [
            'email' => 'intruder@example.com',
            'role' => Role::Adviser->value,
        ])
        ->assertNotFound();

    expect(TenantInvitation::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenantB->id)
        ->where('email', 'intruder@example.com')
        ->exists())->toBeFalse();

    // Keep ownerB referenced so the second workspace stays provisioned.
    expect($ownerB->belongsToTenant($tenantB))->toBeTrue()
        ->and($ownerA->belongsToTenant($tenantA))->toBeTrue();
});

test('custom tenant roles appear in member role options and can be assigned', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, Role::Adviser);
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    TenantRole::query()->create([
        'name' => 'qa-specialist',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
        'is_system' => false,
    ])->syncPermissions([WorkspacePermission::ViewMembers->value]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.members.index', $tenant))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            /** @var array<int, array{value: string, label: string}> $options */
            $options = $page->toArray()['props']['role_options'];

            expect(array_column($options, 'value'))
                ->toContain(Role::Owner->value)
                ->toContain('qa-specialist');
        });

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.members.update', $tenant, [
            'membership' => $membership,
        ]), [
            'role' => 'qa-specialist',
        ])
        ->assertRedirect(workspaceRoute('workspaces.members.index', $tenant));

    actingInWorkspace($tenant);

    expect($member->fresh()->hasRole('qa-specialist'))->toBeTrue();
});

test('members page supports filtering by role', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    workspaceMember($tenant, Role::Adviser);
    workspaceMember($tenant, Role::Reviewer);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.members.index', $tenant).'?role='.Role::Adviser->value)
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            /** @var array<int, array{role: string|null}> $members */
            $members = $page->toArray()['props']['members'];

            expect($page->toArray()['props']['role_filter'])->toBe(Role::Adviser->value)
                ->and($members)->not->toBeEmpty()
                ->and(array_unique(array_filter(array_column($members, 'role'))))->toBe([Role::Adviser->value]);
        });
});
