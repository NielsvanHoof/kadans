<?php

declare(strict_types=1);

use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Support\Session\PortalSessionLimits;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Config;

covers(PortalSessionLimits::class);

beforeEach(function (): void {
    $this->freezeTime();
});

function setFirmPortalWindow(int $tenantId, ?int $idle, ?int $absolute = null): void
{
    TenantSetting::query()->where('tenant_id', $tenantId)->update([
        'portal_session_idle_minutes' => $idle,
        'portal_session_absolute_minutes' => $absolute,
    ]);
}

function firmPortalIdleDeadline(): int
{
    return (int) session(ResolveClientPortalGrant::SESSION_EXPIRES_AT);
}

function firmPortalAbsoluteDeadline(): int
{
    return (int) session(ResolveClientPortalGrant::SESSION_ABSOLUTE_EXPIRES_AT);
}

/**
 * Redemption is the case that matters most here. `portal.exchange` sits outside
 * the resolver middleware on purpose, so no tenant is current while the window
 * is being stamped — a lookup keyed on the current tenant would silently fall
 * back to the platform pair and the firm's choice would never apply.
 */
test('a firm’s shorter window applies from the moment the client opens the link', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 240);

    $context = createPortalDossierWithGrant();
    setFirmPortalWindow($context['tenant']->id, idle: 5, absolute: 60);

    // A client arriving from their email has no tenant in context. The test
    // process keeps whichever tenant provisionWorkspace() made current, so
    // without this the assertion below would pass even if the lookup were
    // keyed on the current tenant — and it would still be wrong in production.
    Tenant::forgetCurrent();

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'));

    expect(firmPortalIdleDeadline())->toBe(now()->addMinutes(5)->getTimestamp())
        ->and(firmPortalAbsoluteDeadline())->toBe(now()->addMinutes(60)->getTimestamp());
});

test('the shorter idle window keeps applying as the client works', function (): void {
    Config::set('portal.session_idle_minutes', 15);

    $context = createPortalDossierWithGrant();
    setFirmPortalWindow($context['tenant']->id, idle: 5);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->travel(2)->minutes();
    $this->get(route('portal.show'))->assertOk();

    expect(firmPortalIdleDeadline())->toBe(now()->addMinutes(5)->getTimestamp());
});

test('a firm that has chosen nothing gets the platform window', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 240);

    $context = createPortalDossierWithGrant();
    setFirmPortalWindow($context['tenant']->id, idle: null);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    expect(firmPortalIdleDeadline())->toBe(now()->addMinutes(15)->getTimestamp())
        ->and(firmPortalAbsoluteDeadline())->toBe(now()->addMinutes(240)->getTimestamp());
});

test('a window longer than the platform one is clamped when it is read', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    Config::set('portal.session_absolute_minutes', 240);

    $context = createPortalDossierWithGrant();
    // Bypasses the form entirely, the way a hand-edited row would.
    setFirmPortalWindow($context['tenant']->id, idle: 600, absolute: 6000);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    expect(firmPortalIdleDeadline())->toBe(now()->addMinutes(15)->getTimestamp())
        ->and(firmPortalAbsoluteDeadline())->toBe(now()->addMinutes(240)->getTimestamp());
});

test('the ceiling is never tighter than a single idle window', function (): void {
    // Otherwise the first request after redemption would already be too late.
    expect(PortalSessionLimits::of(idleMinutes: 30, absoluteMinutes: 10))
        ->absoluteMinutes->toBe(30)
        ->idleMinutes->toBe(30);
});

test('a firm cannot save a window longer than the platform one', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    Config::set('portal.session_idle_minutes', 15);

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.update', $tenant), [
            'ai_document_overview_enabled' => true,
            'ai_checklist_draft_enabled' => true,
            'ai_reminder_draft_enabled' => true,
            'ai_document_search_enabled' => true,
            'portal_session_idle_minutes' => 45,
        ])
        ->assertSessionHasErrors('portal_session_idle_minutes');

    expect(TenantSetting::query()->where('tenant_id', $tenant->id)->sole())
        ->portal_session_idle_minutes->toBeNull();
});
