<?php

declare(strict_types=1);

use App\Accounts\Contracts\TwoFactorEnforcementSource;
use App\Accounts\Enums\TwoFactorEnforcement;
use App\Accounts\Models\User;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Support\TenantTwoFactorEnforcementSource;

covers(TenantTwoFactorEnforcementSource::class);

function setPlatformEnforcement(TwoFactorEnforcement $mode): void
{
    config([
        'security.two_factor.enforcement' => $mode->value,
        'security.two_factor.grace_days' => 0,
        'security.two_factor.permission' => 'documents.download',
    ]);
}

/**
 * Updates through the model rather than the query builder on purpose: the
 * settings cache is invalidated by TenantSetting's `saved` event, and a mass
 * update fires no model events. Production writes go through the model too, so
 * this keeps the test on the same path.
 */
function setFirmEnforcement(int $tenantId, ?TwoFactorEnforcement $mode): void
{
    TenantSetting::query()
        ->where('tenant_id', $tenantId)
        ->sole()
        ->update(['two_factor_enforcement' => $mode?->value]);
}

test('a firm can require a second factor of everyone while the platform asks less', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::Off);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    setFirmEnforcement($tenant->id, TwoFactorEnforcement::AllStaff);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertRedirect(route('security.edit'));
});

test('a firm inheriting the platform setting is enforced exactly as before', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::Off);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    setFirmEnforcement($tenant->id, null);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();
});

/**
 * The guarantee that matters. `strictest()` is applied by the middleware, not
 * trusted from the source, so a row that says `off` cannot lower the platform
 * floor — which is what lets ProductionConfigurationValidator keep refusing to
 * boot production with enforcement off, for every firm, without knowing that
 * firms exist.
 */
test('a firm cannot weaken enforcement, even straight from the database', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::AllStaff);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    // Bypasses the form, which already rejects this.
    setFirmEnforcement($tenant->id, TwoFactorEnforcement::Off);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertRedirect(route('security.edit'));
});

test('a firm tightening from by-permission to everyone catches staff the platform would not', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::ByPermission);

    ['tenant' => $tenant] = provisionWorkspace();
    // Read-only staff hold no download permission, so `by_permission` alone
    // would let them past.
    $member = workspaceMember($tenant, Role::ReadOnly);

    $this->actingAs($member)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();

    setFirmEnforcement($tenant->id, TwoFactorEnforcement::AllStaff);

    $this->actingAs($member)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertRedirect(route('security.edit'));
});

/**
 * Not simply "an app route": InitializeTenantFromSession makes a firm current
 * on those too when the user belongs to exactly one, so a firm's requirement
 * legitimately reaches its own members on the dashboard. The case that must
 * stay unaffected is someone with no firm in context at all — and one firm's
 * choice must never reach a user who is not in it.
 */
test('a user with no firm in context is untouched by another firm’s requirement', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::Off);

    ['tenant' => $tenant] = provisionWorkspace();
    setFirmEnforcement($tenant->id, TwoFactorEnforcement::AllStaff);

    $outsider = User::factory()->create();

    // provisionWorkspace() leaves its tenant current for the rest of the test
    // process, so without this the assertion would be testing the opposite.
    Tenant::forgetCurrent();

    $response = $this->actingAs($outsider)->get(route('dashboard'));

    expect($response->headers->get('Location'))->not->toBe(route('security.edit'));
});

test('a firm’s requirement reaches its own members on the app routes too', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::Off);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    setFirmEnforcement($tenant->id, TwoFactorEnforcement::AllStaff);

    // One membership, so InitializeTenantFromSession makes that firm current.
    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('a firm requirement is still skipped when the feature is switched off', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::AllStaff);
    config(['fortify.features' => []]);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    setFirmEnforcement($tenant->id, TwoFactorEnforcement::AllStaff);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();
});

test('an enrolled user is let through whatever the firm asks', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::Off);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace(
        owner: User::factory()->withTwoFactor()->create(),
    );
    setFirmEnforcement($tenant->id, TwoFactorEnforcement::AllStaff);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();
});

/**
 * Provider order in bootstrap/providers.php decides which binding wins, and the
 * last one registered is the winner. Reversing Accounts and Tenancy would drop
 * every firm's choice with nothing failing, so this asserts the resolved
 * implementation rather than the ordering.
 */
test('the firm-aware enforcement source is the one that resolves', function (): void {
    expect(resolve(TwoFactorEnforcementSource::class))
        ->toBeInstanceOf(TenantTwoFactorEnforcementSource::class);
});

/**
 * The clamp lives in the middleware rather than being trusted from the source,
 * so this is the property that argument buys: an implementation that actively
 * asks for less still cannot get it. A hostile or simply wrong source can fail
 * to tighten; it cannot loosen.
 */
test('a source asking for less than the platform floor is ignored', function (): void {
    setPlatformEnforcement(TwoFactorEnforcement::AllStaff);

    app()->instance(TwoFactorEnforcementSource::class, new class implements TwoFactorEnforcementSource
    {
        public function forCurrentContext(): ?TwoFactorEnforcement
        {
            return TwoFactorEnforcement::Off;
        }
    });

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertRedirect(route('security.edit'));
});
