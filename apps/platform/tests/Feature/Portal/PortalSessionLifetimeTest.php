<?php

declare(strict_types=1);

use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Support\PortalSessionWindow;
use Illuminate\Support\Facades\Config;

covers(PortalSessionWindow::class);

/*
 * Freeze the clock for every test in this file.
 *
 * These assertions compare a deadline the application stamped during a request
 * against `now()` re-evaluated at assertion time. Both are second-precision, so
 * whenever the wall clock crossed a second boundary in between, the test failed by
 * exactly one second — rarely, and never reproducibly. The tests that call
 * `$this->travel()` were already immune (travel pins Carbon), which is why only the
 * three that do not were flaky.
 *
 * Freezing here rather than in each test keeps a future `now()` assertion from
 * reintroducing it. `travel()` still works: it moves the frozen instant forward.
 */
beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * The idle deadline currently held in the portal session.
 */
function portalIdleDeadline(): int
{
    $idleExpiresAt = session(ResolveClientPortalGrant::SESSION_EXPIRES_AT);

    expect($idleExpiresAt)->toBeInt();

    return (int) $idleExpiresAt;
}

function portalAbsoluteDeadline(): int
{
    $absoluteExpiresAt = session(ResolveClientPortalGrant::SESSION_ABSOLUTE_EXPIRES_AT);

    expect($absoluteExpiresAt)->toBeInt();

    return (int) $absoluteExpiresAt;
}

test('redeeming a link stamps both an idle deadline and an absolute ceiling', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 240);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'));

    expect(portalIdleDeadline())->toBe(now()->addMinutes(15)->getTimestamp())
        ->and(portalAbsoluteDeadline())->toBe(now()->addMinutes(240)->getTimestamp());
});

test('each portal request slides the idle deadline forward', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 240);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $originalIdleDeadline = portalIdleDeadline();
    $originalCeiling = portalAbsoluteDeadline();

    // The gap a client spends reading instructions and photographing a payslip.
    $this->travel(10)->minutes();

    $this->get(route('portal.show'))->assertOk();

    expect(portalIdleDeadline())
        ->toBe(now()->addMinutes(15)->getTimestamp())
        ->toBeGreaterThan($originalIdleDeadline)
        // The ceiling is the whole point: it must not move with the client.
        ->and(portalAbsoluteDeadline())->toBe($originalCeiling);
});

test('a client working steadily is never signed out, however long the form takes', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 240);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Ten minutes per item is a slow phone and a shoebox of receipts. Under the
    // old absolute 15-minute window this client was dead after the second item.
    foreach (range(1, 8) as $ignored) {
        $this->travel(10)->minutes();

        $this->get(route('portal.show'))->assertOk();
    }
});

test('the absolute ceiling ends the session even while the client is active', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 60);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Active throughout, so the idle deadline keeps being renewed...
    foreach (range(1, 5) as $ignored) {
        $this->travel(10)->minutes();

        $this->get(route('portal.show'))->assertOk();
    }

    // ...and the ceiling still lands.
    $this->travel(11)->minutes();

    $this->get(route('portal.show'))
        ->assertRedirect(route('portal.ended'))
        ->assertSessionMissing(ResolveClientPortalGrant::SESSION_GRANT_ID);
});

test('the idle deadline never pushes past the absolute ceiling', function (): void {
    Config::set('portal.session_idle_minutes', 30);
    Config::set('portal.session_absolute_minutes', 45);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // One minute inside the idle window; landing exactly on a deadline counts
    // as expired, which the ceiling test above already covers.
    $this->travel(29)->minutes();

    $this->get(route('portal.show'))->assertOk();

    // 30 more idle minutes would overshoot the ceiling by 14, so it clamps.
    expect(portalIdleDeadline())->toBe(portalAbsoluteDeadline());
});

test('neither deadline outlives the grant that opened the session', function (): void {
    // A generous session on a link that expires in hours, not days.
    Config::set('portal.session_idle_minutes', 60);
    Config::set('portal.session_absolute_minutes', 1440);

    $context = createPortalDossierWithGrant();

    $grantExpiresAt = now()->addMinutes(20);
    $context['grant']->forceFill(['expires_at' => $grantExpiresAt])->save();

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'));

    expect(portalIdleDeadline())->toBe($grantExpiresAt->getTimestamp())
        ->and(portalAbsoluteDeadline())->toBe($grantExpiresAt->getTimestamp());
});

test('the configured idle window is clamped to something usable', function (
    int $configured,
    int $expected,
): void {
    Config::set('portal.session_idle_minutes', $configured);
    Config::set('portal.session_absolute_minutes', 1440);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    expect(portalIdleDeadline())->toBe(now()->addMinutes($expected)->getTimestamp());
})->with([
    'zero becomes the floor' => [0, 1],
    'negative becomes the floor' => [-5, 1],
    'a day is capped at an hour' => [1440, 60],
    'a sane value is left alone' => [20, 20],
]);

test('a session issued before the absolute ceiling existed is ended, not trusted', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Exactly the shape of a session written by the previous release.
    session()->forget(ResolveClientPortalGrant::SESSION_ABSOLUTE_EXPIRES_AT);

    $this->get(route('portal.show'))
        ->assertNotFound()
        ->assertSessionMissing(ResolveClientPortalGrant::SESSION_GRANT_ID);
});

test('a client can deliberately keep the session open', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 240);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));
    $this->get(route('portal.show'))->assertOk();

    $this->travel(14)->minutes();

    $this->from(route('portal.show'))
        ->post(route('portal.session.extend'))
        ->assertRedirect(route('portal.show'));

    expect(portalIdleDeadline())->toBe(now()->addMinutes(15)->getTimestamp());
});

test('keeping the session open cannot outlast the ceiling', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 30);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->travel(14)->minutes();
    $this->from(route('portal.show'))
        ->post(route('portal.session.extend'))
        ->assertRedirect(route('portal.show'));

    $this->travel(14)->minutes();
    $this->from(route('portal.show'))
        ->post(route('portal.session.extend'))
        ->assertRedirect(route('portal.show'));

    // 28 minutes of pressing the button, and the 30-minute ceiling still wins.
    $this->travel(3)->minutes();

    $this->from(route('portal.show'))
        ->post(route('portal.session.extend'))
        ->assertRedirect(route('portal.ended'));
});

test('the portal page tells the client when their session ends', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 240);

    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('portal/show')
            ->where('session.idle_expires_at', now()->addMinutes(15)->toIso8601String())
            ->where('session.absolute_expires_at', now()->addMinutes(240)->toIso8601String()));
});
