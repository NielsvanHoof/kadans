<?php

declare(strict_types=1);

use App\Tenancy\Actions\RenameTenantAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

covers(RenameTenantAction::class);

test('an administrator can rename the firm and change its address', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $administrator = workspaceMember($tenant, Role::Administrator);

    $this->actingAs($administrator)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Beta Advisory',
            'slug' => 'beta-advisory',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/workspaces/beta-advisory/settings');

    expect($tenant->fresh())
        ->name->toBe('Beta Advisory')
        ->slug->toBe('beta-advisory');
});

test('the previous address stops resolving once the firm has moved', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $previousSlug = $tenant->slug;

    $this->withoutVite();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Beta Advisory',
            'slug' => 'beta-advisory',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->get("/workspaces/$previousSlug/settings")
        ->assertNotFound();

    $this->actingAs($owner)
        ->get('/workspaces/beta-advisory/settings')
        ->assertOk();
});

test('renaming refreshes the firm switcher', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Beta Advisory',
            'slug' => 'beta-advisory',
        ])
        ->assertSessionHas('inertia.refresh.workspaces', true);
});

test('the switcher shows the new name on the very next page', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->withoutVite();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Beta Advisory',
            'slug' => 'beta-advisory',
        ])
        ->assertSessionHasNoErrors();

    // The switcher list is a once prop, so a stale entry here would persist
    // for the rest of the session rather than correcting itself.
    $this->actingAs($owner)
        ->get('/workspaces/beta-advisory/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('current_firm.name', 'Beta Advisory')
            ->where('current_firm.slug', 'beta-advisory')
            ->where('workspaces.0.name', 'Beta Advisory'));
});

test('an address another firm already uses is rejected', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $other = Tenant::factory()->create(['slug' => 'taken-address']);

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Beta Advisory',
            'slug' => 'taken-address',
        ])
        ->assertSessionHasErrors('slug');

    expect($tenant->fresh()->slug)->not->toBe('taken-address')
        ->and($other->fresh()->slug)->toBe('taken-address');
});

test('keeping its own address is not treated as a clash', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Renamed Only',
            'slug' => $tenant->slug,
        ])
        ->assertSessionHasNoErrors();

    expect($tenant->fresh()->name)->toBe('Renamed Only');
});

test('a typed address is turned into a usable one', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Beta Advisory',
            'slug' => 'Beta Advisory & Co',
        ])
        ->assertSessionHasNoErrors();

    expect($tenant->fresh()->slug)->toBe('beta-advisory-co');
});

test('an address that reduces to nothing is refused', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $previousSlug = $tenant->slug;

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Beta Advisory',
            'slug' => '///',
        ])
        ->assertSessionHasErrors('slug');

    expect($tenant->fresh()->slug)->toBe($previousSlug);
});

test('staff without the settings permission cannot rename the firm', function (Role $role): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, $role);
    $previousSlug = $tenant->slug;

    $this->actingAs($member)
        ->patch(workspaceRoute('workspaces.settings.firm.update', $tenant), [
            'name' => 'Beta Advisory',
            'slug' => 'beta-advisory',
        ])
        ->assertForbidden();

    expect($tenant->fresh()->slug)->toBe($previousSlug);
})->with([
    'adviser' => Role::Adviser,
    'reviewer' => Role::Reviewer,
    'read only' => Role::ReadOnly,
]);
