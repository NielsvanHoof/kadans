<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Tenancy\Console\Commands\CreateOwnerCommand;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;

covers(CreateOwnerCommand::class);

test('tenancy create-owner provisions a verified owner and firm', function (): void {
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

test('tenancy create-owner creates a verified user without a firm when firm is omitted', function (): void {
    $this->artisan('tenancy:create-owner', [
        'name' => 'Ada Owner',
        'email' => 'ada@example.com',
        '--password' => 'password',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Ada Owner')
        ->and(Tenant::query()->count())->toBe(0)
        ->and(TenantMembership::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('tenancy create-owner rejects duplicate emails', function (): void {
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

test('tenancy create-owner prompts for details and allows optional inputs', function (): void {
    $roleOptions = collect(Role::cases())
        ->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])
        ->all();

    $this->artisan('tenancy:create-owner')
        ->expectsQuestion('Name', 'Grace Hopper')
        ->expectsQuestion('Email', 'grace@example.com')
        ->expectsQuestion('Firm', "Grace Hopper's firm")
        ->expectsChoice('Role', Role::Owner->value, $roleOptions)
        ->expectsQuestion('Password', '')
        ->assertSuccessful();

    $user = User::query()->where('email', 'grace@example.com')->firstOrFail();
    $tenant = Tenant::query()->where('slug', 'grace-hoppers-firm')->firstOrFail();

    expect($user->name)->toBe('Grace Hopper')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBeEmpty()
        ->and($tenant->name)->toBe("Grace Hopper's firm");
});

test('tenancy create-owner prompts and skips firm when left blank', function (): void {
    $this->artisan('tenancy:create-owner')
        ->expectsQuestion('Name', 'Grace Hopper')
        ->expectsQuestion('Email', 'grace@example.com')
        ->expectsQuestion('Firm', '')
        ->expectsQuestion('Password', '')
        ->assertSuccessful();

    $user = User::query()->where('email', 'grace@example.com')->firstOrFail();

    expect($user->name)->toBe('Grace Hopper')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Tenant::query()->count())->toBe(0);
});
