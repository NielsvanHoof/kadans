<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Models\OAuthConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Configure Google credentials so the provider appears as enabled.
 */
function enableGoogleOAuth(): void
{
    Config::set('services.google.client_id', 'test-google-client-id');
    Config::set('services.google.client_secret', 'test-google-client-secret');
    Config::set('services.google.redirect', 'http://localhost/auth/google/callback');
}

function fakeGoogleUser(array $overrides = []): SocialiteUser
{
    return SocialiteUser::fake([
        'id' => 'google-sub-123',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'token' => 'access-token',
        'refreshToken' => 'refresh-token',
        'expiresIn' => 3600,
        'approvedScopes' => OAuthProvider::Google->scopes(),
        ...$overrides,
    ]);
}

test('login screen shows google when configured', function (): void {
    Config::set('services.microsoft.client_id');
    enableGoogleOAuth();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/login')
            ->where('oauthProviders', ['google']),
        );
});

test('google authorize url requests offline access and consent', function (?string $reconsent): void {
    enableGoogleOAuth();

    $query = $reconsent === null ? [] : ['reconsent' => $reconsent];

    $response = $this->get(route('oauth.redirect', ['provider' => 'google', ...$query]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toBeString();

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $params);

    expect($params['access_type'] ?? null)->toBe('offline')
        ->and($params['prompt'] ?? '')->toContain('consent')
        ->and($params['prompt'] ?? '')->toContain('select_account');
})->with([
    'without reconsent' => [null],
    'with reconsent' => ['1'],
]);

test('matching verified email logs in via google', function (): void {
    enableGoogleOAuth();

    $user = User::factory()->create([
        'email' => 'ada@example.com',
        'email_verified_at' => now(),
    ]);

    Socialite::fake('google', fakeGoogleUser([
        'email' => $user->email,
    ]));

    $this->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);
    expect(OAuthConnection::query()->where('user_id', $user->id)->where('provider', OAuthProvider::Google)->exists())
        ->toBeTrue();
});

test('authenticated user can link google from settings', function (): void {
    enableGoogleOAuth();

    $user = User::factory()->create([
        'email' => 'ada@example.com',
    ]);

    Socialite::fake('google', fakeGoogleUser([
        'id' => 'link-google-sub',
        'email' => $user->email,
    ]));

    $this->actingAs($user)
        ->withSession([
            'auth.password_confirmed_at' => Date::now()->getTimestamp(),
            'oauth.intent' => 'link',
        ])
        ->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(route('integrations.edit'));

    expect(OAuthConnection::query()->where('user_id', $user->id)->where('provider', OAuthProvider::Google)->exists())
        ->toBeTrue();
});

test('integrations page lists google when configured', function (): void {
    enableGoogleOAuth();
    Config::set('services.microsoft.client_id');

    $user = User::factory()->create(['email' => 'ada@example.com']);

    OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $user->id,
        'email' => 'ada@example.com',
    ]);

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/integrations')
            ->where('oauthProviders', ['google'])
            ->has('oauthConnections', 1)
            ->where('oauthConnections.0.provider', 'google'),
        );
});

test('unconfigured google routes return not found', function (): void {
    Config::set('services.google.client_id');

    $this->get(route('oauth.redirect', ['provider' => 'google']))
        ->assertNotFound();
});
