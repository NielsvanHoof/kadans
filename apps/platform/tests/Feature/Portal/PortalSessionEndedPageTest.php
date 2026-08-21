<?php

declare(strict_types=1);

use App\ClientPortal\Enums\PortalSessionEndReason;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use Inertia\Testing\AssertableInertia as Assert;

covers(PortalSessionEndReason::class);

test('a timed-out client is told their work is safe instead of being 404d', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->travel(16)->minutes();

    $this->get(route('portal.show'))
        ->assertRedirect(route('portal.ended'));

    $this->get(route('portal.ended'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/ended')
            ->where('reason', PortalSessionEndReason::TimedOut->value)
            // Branded, because a client who has no idea why the page stopped
            // working should at least recognise whose page it was.
            ->where('firm_name', 'Acme Accountants'));
});

test('a revoked grant is told access ended, not that it timed out', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    actingInWorkspace($context['tenant']);
    $context['grant']->forceFill(['revoked_at' => now()])->save();

    $this->get(route('portal.show'))
        ->assertRedirect(route('portal.ended'));

    $this->get(route('portal.ended'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/ended')
            ->where('reason', PortalSessionEndReason::AccessEnded->value));
});

test('an expired grant reports ended access rather than a timeout', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    actingInWorkspace($context['tenant']);
    // Still inside the session window, but the link itself is done.
    $context['grant']->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->get(route('portal.show'))
        ->assertRedirect(route('portal.ended'));

    $this->get(route('portal.ended'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('reason', PortalSessionEndReason::AccessEnded->value));
});

test('a client with no portal session at all still gets a bare 404', function (): void {
    createPortalDossierWithGrant();

    // The disclosure above is only safe because it requires a signed-cookie
    // session a successful exchange wrote. Without one there is nothing to
    // disclose, and a probe must not learn that portal routes exist.
    $this->get(route('portal.show'))->assertNotFound();
});

test('the ended page keeps the portal hardening headers', function (): void {
    $this->get(route('portal.ended'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('X-Frame-Options', 'DENY');
});

test('the ended page defaults to the timeout copy when visited cold', function (): void {
    $this->get(route('portal.ended'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/ended')
            ->where('reason', PortalSessionEndReason::TimedOut->value)
            ->where('firm_name', null));
});

test('the reason is consumed once, so a refresh does not resurrect it', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    actingInWorkspace($context['tenant']);
    $context['grant']->forceFill(['revoked_at' => now()])->save();

    $this->get(route('portal.show'))->assertRedirect(route('portal.ended'));

    $this->get(route('portal.ended'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('reason', PortalSessionEndReason::AccessEnded->value));

    // Flashed, not stored: the second read falls back to the neutral copy.
    $this->get(route('portal.ended'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('reason', PortalSessionEndReason::TimedOut->value));
});

test('a portal session whose grant row is gone gets a 404, not an explanation', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    actingInWorkspace($context['tenant']);
    $context['grant']->delete();

    // A missing row is a server-side inconsistency, not a client-facing state.
    $this->get(route('portal.show'))
        ->assertNotFound()
        ->assertSessionMissing(ResolveClientPortalGrant::SESSION_GRANT_ID);
});
