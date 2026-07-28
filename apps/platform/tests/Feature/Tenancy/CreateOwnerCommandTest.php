<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('tenancy create-owner provisions a verified owner and firm', function () {
    $this->artisan('tenancy:create-owner', [
        'name' => 'Ada Owner',
        'email' => 'ada@example.com',
        '--firm' => 'Ada Accountants',
        '--password' => 'password',
        '--role' => Role::Owner->value,
    ])->assertSuccessful();

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $tenant = Tenant::query()->where('slug', 'ada-accountants')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Ada Owner');

    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($membership->status)->toBe(TenantMembershipStatus::Active);

    actingInWorkspace($tenant);
    expect($user->fresh()->hasRole(Role::Owner->value))->toBeTrue();
});

test('tenancy create-owner rejects duplicate emails', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('tenancy:create-owner', [
        'name' => 'Ada Owner',
        'email' => 'ada@example.com',
        '--firm' => 'Ada Accountants',
        '--password' => 'password',
        '--role' => Role::Owner->value,
    ])->assertFailed();

    expect(Tenant::query()->count())->toBe(0);
});

test('tenancy create-owner prompts for details and allows optional inputs', function () {
    $roleOptions = collect(Role::cases())
        ->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])
        ->all();

    $this->artisan('tenancy:create-owner')
        ->expectsQuestion('Name', 'Grace Hopper')
        ->expectsQuestion('Email', 'grace@example.com')
        ->expectsChoice('Role', Role::Owner->value, $roleOptions)
        ->expectsQuestion('Firm', "Grace Hopper's firm")
        ->expectsQuestion('Password', '')
        ->assertSuccessful();

    $user = User::query()->where('email', 'grace@example.com')->firstOrFail();
    $tenant = Tenant::query()->where('slug', 'grace-hoppers-firm')->firstOrFail();

    expect($user->name)->toBe('Grace Hopper')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe('')
        ->and($tenant->name)->toBe("Grace Hopper's firm");
});
