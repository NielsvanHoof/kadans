<?php

declare(strict_types=1);

use App\Accounts\Enums\PlatformRole;
use App\Accounts\Models\User;
use App\Platform\Console\Commands\SupportSession\GrantSupportCommand;
use App\Platform\Console\Commands\SupportSession\RevokeSupportCommand;

covers(GrantSupportCommand::class, RevokeSupportCommand::class);

test('platform grant-support assigns the support role', function (): void {
    $user = User::factory()->create(['email' => 'eng@example.com']);

    $this->artisan('platform:grant-support', [
        'email' => 'eng@example.com',
    ])->assertSuccessful();

    expect($user->fresh()->platform_role)->toBe(PlatformRole::Support)
        ->and($user->fresh()->isPlatformSupport())->toBeTrue();
});

test('platform revoke-support clears the support role', function (): void {
    $user = User::factory()->platformSupport()->create(['email' => 'eng@example.com']);

    $this->artisan('platform:revoke-support', [
        'email' => 'eng@example.com',
    ])->assertSuccessful();

    expect($user->fresh()->platform_role)->toBeNull()
        ->and($user->fresh()->isPlatformSupport())->toBeFalse();
});

test('platform grant-support fails for unknown emails', function (): void {
    $this->artisan('platform:grant-support', [
        'email' => 'missing@example.com',
    ])->assertFailed();
});
