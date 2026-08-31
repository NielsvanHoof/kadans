<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Models\OAuthConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Configure Microsoft credentials so the provider appears as enabled.
 */
function enableMicrosoftOAuth(): void
{
    Config::set('services.microsoft.client_id', 'test-microsoft-client-id');
    Config::set('services.microsoft.client_secret', 'test-microsoft-client-secret');
    Config::set('services.microsoft.redirect', 'http://localhost/auth/microsoft/callback');
    Config::set('services.microsoft.tenant', 'organizations');
}

function fakeMicrosoftUser(array $overrides = []): SocialiteUser
{
    return SocialiteUser::fake([
        'id' => 'entra-oid-123',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'token' => 'access-token',
        'refreshToken' => 'refresh-token',
        'expiresIn' => 3600,
        'approvedScopes' => OAuthProvider::Microsoft->scopes(),
        ...$overrides,
    ]);
}

test('login screen hides microsoft when unconfigured', function (): void {
    Config::set('services.microsoft.client_id');
    Config::set('services.google.client_id');

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/login')
            ->where('oauthProviders', []),
        );
});

test('login screen shows microsoft when configured', function (): void {
    Config::set('services.google.client_id');
    enableMicrosoftOAuth();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/login')
            ->where('oauthProviders', ['microsoft']),
        );
});

test('login screen lists google before microsoft when both configured', function (): void {
    enableMicrosoftOAuth();
    Config::set('services.google.client_id', 'test-google-client-id');
    Config::set('services.google.client_secret', 'test-google-client-secret');
    Config::set('services.google.redirect', 'http://localhost/auth/google/callback');

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/login')
            ->where('oauthProviders', ['google', 'microsoft']),
        );
});

test('guest is redirected to microsoft authorize url', function (): void {
    enableMicrosoftOAuth();
    Socialite::fake('microsoft');

    $this->get(route('oauth.redirect', ['provider' => 'microsoft']))
        ->assertRedirect();
});

test('inertia visit to oauth redirect uses location response', function (): void {
    enableMicrosoftOAuth();
    Socialite::fake('microsoft');

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
    ])
        ->get(route('oauth.redirect', ['provider' => 'microsoft']))
        ->assertConflict()
        ->assertHeader('X-Inertia-Location');
});

test('unknown microsoft email stays guest and creates no user', function (): void {
    enableMicrosoftOAuth();
    Socialite::fake('microsoft', fakeMicrosoftUser([
        'email' => 'nobody@example.com',
    ]));

    $userCount = User::query()->count();

    $this->get(route('oauth.callback', ['provider' => 'microsoft']))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::query()->count())->toBe($userCount)
        ->and(OAuthConnection::query()
            ->count())
        ->toBe(0);
});

test('unverified email stays guest', function (): void {
    enableMicrosoftOAuth();

    $user = User::factory()->unverified()->create([
        'email' => 'ada@example.com',
    ]);

    Socialite::fake('microsoft', fakeMicrosoftUser([
        'email' => $user->email,
    ]));

    $this->get(route('oauth.callback', ['provider' => 'microsoft']))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(OAuthConnection::query()->count())->toBe(0);
});

test('verified email match logs in and creates connection', function (): void {
    enableMicrosoftOAuth();

    $user = User::factory()->create([
        'email' => 'ada@example.com',
    ]);

    Socialite::fake('microsoft', fakeMicrosoftUser([
        'id' => 'entra-oid-new',
        'email' => 'Ada@Example.com',
    ]));

    $this->get(route('oauth.callback', ['provider' => 'microsoft']))
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);

    $connection = OAuthConnection::query()->where('user_id', $user->id)->first();

    expect($connection)->not->toBeNull()
        ->and($connection->provider)->toBe(OAuthProvider::Microsoft)
        ->and($connection->provider_user_id)->toBe('entra-oid-new')
        ->and($connection->email)->toBe('ada@example.com')
        ->and($connection->access_token)->toBe('access-token')
        ->and($connection->refresh_token)->toBe('refresh-token');
});

test('existing oid logs into linked user even when graph email changed', function (): void {
    enableMicrosoftOAuth();

    $user = User::factory()->create([
        'email' => 'ada@example.com',
    ]);

    OAuthConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => OAuthProvider::Microsoft,
        'provider_user_id' => 'stable-oid',
        'email' => 'ada@example.com',
    ]);

    Socialite::fake('microsoft', fakeMicrosoftUser([
        'id' => 'stable-oid',
        'email' => 'ada.renamed@example.com',
    ]));

    $this->get(route('oauth.callback', ['provider' => 'microsoft']))
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(OAuthConnection::query()->count())->toBe(1);
});

test('users with two factor enabled are redirected to two factor challenge', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    enableMicrosoftOAuth();

    $user = User::factory()->withTwoFactor()->create([
        'email' => 'ada@example.com',
    ]);

    Socialite::fake('microsoft', fakeMicrosoftUser([
        'email' => $user->email,
    ]));

    $response = $this->get(route('oauth.callback', ['provider' => 'microsoft']));

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('authenticated user can link microsoft from settings', function (): void {
    enableMicrosoftOAuth();

    $user = User::factory()->create([
        'email' => 'ada@example.com',
    ]);

    Socialite::fake('microsoft', fakeMicrosoftUser([
        'id' => 'link-oid',
        'email' => $user->email,
    ]));

    $this->actingAs($user)
        ->withSession([
            'auth.password_confirmed_at' => Date::now()->getTimestamp(),
            'oauth.intent' => 'link',
        ])
        ->get(route('oauth.callback', ['provider' => 'microsoft']))
        ->assertRedirect(route('integrations.edit'));

    expect(OAuthConnection::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('oid owned by another user is rejected when linking', function (): void {
    enableMicrosoftOAuth();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);

    OAuthConnection::factory()->create([
        'user_id' => $owner->id,
        'provider' => OAuthProvider::Microsoft,
        'provider_user_id' => 'taken-oid',
    ]);

    Socialite::fake('microsoft', fakeMicrosoftUser([
        'id' => 'taken-oid',
        'email' => $other->email,
    ]));

    $this->actingAs($other)
        ->withSession([
            'auth.password_confirmed_at' => Date::now()->getTimestamp(),
            'oauth.intent' => 'link',
        ])
        ->get(route('oauth.callback', ['provider' => 'microsoft']))
        ->assertRedirect(route('integrations.edit'));

    expect(OAuthConnection::query()->where('user_id', $other->id)->exists())->toBeFalse();
});

test('user can unlink microsoft connection', function (): void {
    enableMicrosoftOAuth();

    $user = User::factory()->create();

    OAuthConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => OAuthProvider::Microsoft,
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->delete(route('oauth.destroy', ['provider' => 'microsoft']))
        ->assertRedirect();

    expect(OAuthConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('integrations page lists oauth connections when configured', function (): void {
    Config::set('services.google.client_id');
    enableMicrosoftOAuth();

    $user = User::factory()->create(['email' => 'ada@example.com']);

    OAuthConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => OAuthProvider::Microsoft,
        'email' => 'ada@example.com',
    ]);

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/integrations')
            ->where('oauthProviders', ['microsoft'])
            ->has('oauthConnections', 1)
            ->where('oauthConnections.0.provider', 'microsoft')
            ->where('oauthConnections.0.email', 'ada@example.com'),
        );
});

test('integrations page shows empty providers when microsoft is unconfigured', function (): void {
    Config::set('services.microsoft.client_id');
    Config::set('services.google.client_id');

    $this->withoutVite();

    $this->actingAs(User::factory()->create())
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/integrations')
            ->where('oauthProviders', [])
            ->where('oauthConnections', []),
        );
});

test('unconfigured microsoft routes return not found', function (): void {
    Config::set('services.microsoft.client_id');

    $this->get(route('oauth.redirect', ['provider' => 'microsoft']))
        ->assertNotFound();
});
