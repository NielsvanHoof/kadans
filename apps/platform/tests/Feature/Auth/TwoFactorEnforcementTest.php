<?php

declare(strict_types=1);

use App\Accounts\Enums\TwoFactorEnforcement;
use App\Accounts\Models\User;
use App\Tenancy\Enums\Role;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;

/**
 * Enforcement is applied to the authenticated app and workspace route groups
 * only — never to settings or auth routes. The lockout tests below are the
 * point of that split: an unenrolled user must always be able to reach the
 * enrolment page, confirm their password, and log out.
 */
function enforceTwoFactor(TwoFactorEnforcement $mode, int $graceDays = 0): void
{
    config([
        'security.two_factor.enforcement' => $mode->value,
        'security.two_factor.grace_days' => $graceDays,
    ]);
}

/**
 * A bare account is redirected to onboarding, so "was not enforced" cannot be
 * asserted as a 200 — it has to be asserted as "not diverted to enrolment".
 */
function assertNotDivertedToEnrolment(TestResponse $response): void
{
    expect($response->headers->get('Location'))->not->toBe(route('security.edit'));
}

test('enforcement off lets an unenrolled user work normally', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::Off);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();
});

test('an unenrolled user is sent to the enrolment page', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    ['owner' => $owner] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('an enrolled user is let through', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace(
        owner: User::factory()->withTwoFactor()->create(),
    );

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();
});

/**
 * Fortify's `confirm` option is on, so a secret without a confirmation is an
 * abandoned enrolment: the user never proved they can generate a code.
 */
test('a half-finished enrolment does not count as enrolled', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    ['owner' => $owner] = provisionWorkspace(
        owner: User::factory()->withTwoFactor()->create(['two_factor_confirmed_at' => null]),
    );

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('a new account is left alone until its grace window closes', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff, graceDays: 14);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace(
        owner: User::factory()->create(['created_at' => now()->subDays(3)]),
    );

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();
});

test('an account past its grace window is enforced', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff, graceDays: 14);

    ['owner' => $owner] = provisionWorkspace(
        owner: User::factory()->create(['created_at' => now()->subDays(15)]),
    );

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('workspace routes are enforced too', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    ['tenant' => $tenant, 'owner' => $owner] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertRedirect(route('security.edit'));
});

/**
 * The anti-lockout guarantee. The enrolment page sits behind RequirePassword,
 * so it redirects to password confirmation — what matters is that it never
 * redirects back to itself, which would be an inescapable loop.
 */
test('an enforced user can still reach the enrolment page', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    $response = $this->actingAs(User::factory()->create())->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
    assertNotDivertedToEnrolment($response);
});

test('an enforced user can still confirm their password and edit their profile', function (string $route): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertOk();
})->with([
    'password confirmation' => ['password.confirm'],
    'profile settings' => ['profile.edit'],
]);

test('an enforced user can still log out', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect();

    $this->assertGuest();
});

/**
 * A redirect would be followed by fetch and the settings page rendered into a
 * JSON slot, so XHR callers get a status they can act on instead.
 */
test('xhr callers are refused rather than redirected', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    ['owner' => $owner] = provisionWorkspace();

    $this->actingAs($owner)
        ->getJson(route('dashboard'))
        ->assertForbidden();
});

test('by_permission enforces only staff who can download documents', function (Role $role, bool $enforced): void {
    enforceTwoFactor(TwoFactorEnforcement::ByPermission);

    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, $role);

    $response = $this->actingAs($member)->get(workspaceRoute('workspaces.dashboard', $tenant));

    if ($enforced) {
        $response->assertRedirect(route('security.edit'));

        return;
    }

    $response->assertOk();
    assertNotDivertedToEnrolment($response);
})->with([
    // Administrator and Adviser carry documents.download by default; ReadOnly does not.
    'administrator' => [Role::Administrator, true],
    'adviser' => [Role::Adviser, true],
    'read-only' => [Role::ReadOnly, false],
]);

/**
 * A typo in the deploy environment must not silently switch enforcement off.
 */
test('an unrecognised enforcement mode fails closed', function (): void {
    config([
        'security.two_factor.enforcement' => 'evyerone',
        'security.two_factor.grace_days' => 0,
    ]);

    ['owner' => $owner] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

/**
 * Otherwise turning the feature off would strand every unenrolled user at a
 * settings page that no longer offers enrolment.
 */
test('disabling the Fortify feature disables enforcement', function (): void {
    enforceTwoFactor(TwoFactorEnforcement::AllStaff);

    // Features::canManageTwoFactorAuthentication() reads this array.
    config(['fortify.features' => [Features::resetPasswords(), Features::emailVerification()]]);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();
});
