<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('platform grant-support assigns the support role', function () {
    $user = User::factory()->create(['email' => 'eng@example.com']);

    $this->artisan('platform:grant-support', [
        'email' => 'eng@example.com',
    ])->assertSuccessful();

    expect($user->fresh()->platform_role)->toBe(PlatformRole::Support)
        ->and($user->fresh()->isPlatformSupport())->toBeTrue();
});

test('platform revoke-support clears the support role', function () {
    $user = User::factory()->platformSupport()->create(['email' => 'eng@example.com']);

    $this->artisan('platform:revoke-support', [
        'email' => 'eng@example.com',
    ])->assertSuccessful();

    expect($user->fresh()->platform_role)->toBeNull()
        ->and($user->fresh()->isPlatformSupport())->toBeFalse();
});

test('platform grant-support fails for unknown emails', function () {
    $this->artisan('platform:grant-support', [
        'email' => 'missing@example.com',
    ])->assertFailed();
});
