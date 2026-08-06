<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function (): void {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification): true {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): true {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('a spoofed Host header never appears in the generated password reset URL', function (): void {
    Notification::fake();

    // Mirrors what AppServiceProvider forces in production, so the reset
    // link is derived from the configured app URL, not the request Host.
    URL::useOrigin('https://app.example.com');
    URL::forceScheme('https');

    $user = User::factory()->create();

    $this->withHeaders(['Host' => 'evil.attacker.test'])
        ->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): true {
        $mail = $notification->toMail($user);

        expect($mail->actionUrl)
            ->toStartWith('https://app.example.com/')
            ->not->toContain('evil.attacker.test');

        return true;
    });
});

test('POST /forgot-password is throttled and returns 429 once the limit is exceeded', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertStatus(302);
    }

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertStatus(429);
});

test('POST /reset-password is throttled and returns 429 once the limit is exceeded', function (): void {
    $user = User::factory()->create();

    $payload = [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->post(route('password.update'), $payload)
            ->assertStatus(302);
    }

    $this->post(route('password.update'), $payload)
        ->assertStatus(429);
});

test('password cannot be reset with invalid token', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
