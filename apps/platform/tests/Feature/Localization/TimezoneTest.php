<?php

declare(strict_types=1);

use App\Accounts\Actions\Localization\ResolveApplicationTimezoneAction;
use App\Accounts\Models\User;
use App\Accounts\Support\Localization\DisplayTimezones;
use App\ClientPortal\Notifications\Outreach\ClientPortalInviteNotification;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Notifications\Members\WorkspaceMemberInviteNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;

covers(ResolveApplicationTimezoneAction::class, DisplayTimezones::class);

test('timezone is resolved from the authenticated user preference', function (): void {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', 'Asia/Tokyo'));
});

test('timezone is resolved from the cookie when the user has no preference', function (): void {
    $user = User::factory()->create(['timezone' => null]);

    // Unencrypted, because JS writes this cookie in plaintext. If bootstrap/app.php
    // stops excluding it from encryptCookies, this is the test that fails.
    $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'Asia/Tokyo')
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', 'Asia/Tokyo'));
});

test('the stored preference outranks the browser-detected cookie', function (): void {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);

    $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'Europe/Amsterdam')
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', 'Asia/Tokyo'));
});

test('timezone falls back to the configured display timezone', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', 'Europe/Amsterdam'));
});

test('a guest request still resolves a timezone', function (): void {
    $this->withUnencryptedCookie('timezone', 'America/New_York')
        ->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', 'America/New_York'));
});

test('an unusable configured timezone degrades to UTC rather than throwing', function (): void {
    Config::set('app.display_timezone', 'Europe/Amsterdm');

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', 'UTC'));
});

// A hostile or stale cookie reaches Intl.DateTimeFormat in the browser and Carbon
// on the server, so it must never be echoed back unvalidated. Anything unusable
// falls through to the configured default; nothing throws.
test('an unusable cookie is ignored', function (string $cookieValue): void {
    $this->withUnencryptedCookie('timezone', $cookieValue)
        ->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', 'Europe/Amsterdam'));
})->with([
    // PHP's DateTimeZone accepts this, but Intl.DateTimeFormat throws RangeError.
    'zone abbreviation' => 'CEST',
    // Valid to PHP, but not a named zone we can hand to a formatter.
    'raw offset' => '+02:00',
    // In timezone_identifiers_list(ALL_WITH_BC), but rejected by ICU.
    'tzdata filename' => 'localtime',
    'tzdata index' => 'tzdata.zi',
    'factory placeholder' => 'Factory',
    'unknown zone' => 'Mars/Olympus',
    'empty' => '',
    'path traversal' => '../../etc/passwd',
    'script injection' => '"><script>alert(1)</script>',
]);

// Live ICU aliases that PHP's own identifier lists omit. Accepting these is the
// reason validation goes through ICU — otherwise these users get silently
// downgraded to the default.
test('an ICU alias the PHP zone list omits is accepted', function (string $cookieValue): void {
    $this->withUnencryptedCookie('timezone', $cookieValue)
        ->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', $cookieValue));
})->with([
    'etc prefix' => 'Etc/UTC',
    'legacy india' => 'Asia/Calcutta',
    'legacy us' => 'US/Pacific',
]);

test('a user can store a timezone preference', function (): void {
    $user = User::factory()->create(['timezone' => null]);

    $this->actingAs($user)
        ->from(route('language.edit'))
        ->patch(route('timezone.update'), ['timezone' => 'Asia/Tokyo'])
        ->assertRedirect(route('language.edit'))
        // Plain, not encrypted — the client-side detector reads and writes it too.
        ->assertPlainCookie('timezone', 'Asia/Tokyo');

    expect($user->fresh()?->timezone)->toBe('Asia/Tokyo');
});

test('storing an unrecognised timezone is rejected', function (): void {
    $user = User::factory()->create(['timezone' => null]);

    $this->actingAs($user)
        ->from(route('language.edit'))
        ->patch(route('timezone.update'), ['timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors('timezone');

    expect($user->fresh()?->timezone)->toBeNull();
});

test('storing a zone abbreviation is rejected even though PHP accepts it', function (): void {
    $user = User::factory()->create(['timezone' => null]);

    $this->actingAs($user)
        ->patch(route('timezone.update'), ['timezone' => 'CEST'])
        ->assertSessionHasErrors('timezone');

    expect($user->fresh()?->timezone)->toBeNull();
});

test('the settings screen offers the resolved zone as an option', function (): void {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);

    // Tokyo is outside the curated shortlist, so it must be appended — otherwise
    // the control would render with nothing selected.
    $this->actingAs($user)
        ->get(route('language.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('timezone', 'Asia/Tokyo')
            ->where(
                'available_timezones',
                fn (Collection $timezones): bool => $timezones
                    ->pluck('value')
                    ->contains('Asia/Tokyo'),
            ));
});

test('preferredTimezone falls back when the attribute was never loaded', function (): void {
    // The factory does not set timezone, so the attribute is missing rather than
    // null — reading it via $this->timezone would throw under
    // preventsAccessingMissingAttributes().
    $user = User::factory()->create();

    expect($user->preferredTimezone())->toBe('Europe/Amsterdam');
});

test('preferredTimezone returns the stored zone', function (): void {
    $user = User::factory()->create(['timezone' => 'America/Chicago']);

    expect($user->preferredTimezone())->toBe('America/Chicago');
});

// Queued mail has no request and no cookie, so the zone must be carried on the
// notification itself. These assert the rendered line, not the plumbing.
test('member invite mail renders the expiry in the recipient timezone', function (): void {
    $notification = new WorkspaceMemberInviteNotification(
        invitationId: 1,
        firmName: 'Acme',
        role: 'member',
        acceptUrl: 'https://example.test/invitations/token',
        expiresAt: CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC'),
        displayTimezone: 'Asia/Tokyo',
    );

    $lines = implode(' ', $notification->toMail(new AnonymousNotifiable)->introLines);

    // 12:00 UTC is 21:00 in Tokyo, and the abbreviation makes that unambiguous.
    expect($lines)->toContain('15 July 2026, 21:00 (JST)');
});

test('client portal invite mail renders the expiry in the configured display timezone', function (): void {
    // Provisioned rather than a bare factory: the dossier's client is tenant-scoped
    // and resolves to null without a current tenant, which the notification rejects.
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $notification = new ClientPortalInviteNotification(
        grantId: 1,
        dossier: $dossier,
        portalUrl: 'https://example.test/portal/token',
        expiresAt: CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC'),
        firmName: 'Acme',
    );

    $mail = $notification->toMail(new AnonymousNotifiable);
    $lines = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

    // Clients have no account, so this is app.display_timezone: 14:00 CEST.
    expect($lines)->toContain('15 July 2026, 14:00 (CEST)');
});
