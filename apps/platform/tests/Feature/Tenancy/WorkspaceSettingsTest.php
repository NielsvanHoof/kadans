<?php

declare(strict_types=1);

use App\Accounts\Enums\TwoFactorEnforcement;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;

test('owner can view firm settings', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->withoutVite();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.settings.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/settings/index')
            ->where('settings.ai.document_overview', true)
            ->where('settings.ai.checklist_draft', true)
            ->where('settings.ai.reminder_draft', true)
            ->where('settings.ai.document_search', true));
});

test('administrator can view and update firm settings', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $administrator = workspaceMember($tenant, Role::Administrator);

    $this->withoutVite();

    $this->actingAs($administrator)
        ->get(workspaceRoute('workspaces.settings.index', $tenant))
        ->assertOk();

    $this->actingAs($administrator)
        ->patch(workspaceRoute('workspaces.settings.update', $tenant), [
            'ai_document_overview_enabled' => false,
            'ai_checklist_draft_enabled' => true,
            'ai_reminder_draft_enabled' => true,
            'ai_document_search_enabled' => true,
        ])
        ->assertRedirect(workspaceRoute('workspaces.settings.index', $tenant));

    expect(TenantSetting::query()->where('tenant_id', $tenant->id)->first())
        ->not->toBeNull()
        ->ai_document_overview_enabled->toBeFalse();
});

test('adviser cannot view firm settings', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $adviser = workspaceMember($tenant, Role::Adviser);

    $this->actingAs($adviser)
        ->get(workspaceRoute('workspaces.settings.index', $tenant))
        ->assertForbidden();
});

test('owner can update firm settings', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.update', $tenant), [
            'ai_document_overview_enabled' => false,
            'ai_checklist_draft_enabled' => false,
            'ai_reminder_draft_enabled' => true,
            'ai_document_search_enabled' => false,
        ])
        ->assertRedirect(workspaceRoute('workspaces.settings.index', $tenant));

    $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->first();

    expect($settings)->not->toBeNull()
        ->and($settings->ai_document_overview_enabled)->toBeFalse()
        ->and($settings->ai_checklist_draft_enabled)->toBeFalse()
        ->and($settings->ai_reminder_draft_enabled)->toBeTrue()
        ->and($settings->ai_document_search_enabled)->toBeFalse();
});

test('new firms start with all ai features enabled', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->first();

    expect($settings)->not->toBeNull()
        ->and($settings->ai_document_overview_enabled)->toBeTrue()
        ->and($settings->ai_checklist_draft_enabled)->toBeTrue()
        ->and($settings->ai_reminder_draft_enabled)->toBeTrue()
        ->and($settings->ai_document_search_enabled)->toBeTrue();
});

test('firm settings are shared with every workspace member', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $administrator = workspaceMember($tenant, Role::Administrator);

    TenantSetting::query()->where('tenant_id', $tenant->id)->update([
        'ai_checklist_draft_enabled' => false,
    ]);

    $this->withoutVite();

    $this->actingAs($administrator)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('firm_settings.ai.checklist_draft', false)
            ->where('firm_settings.ai.document_overview', true)
            ->where('firm_settings.ai.document_search', true));
});

test('an administrator can set the firm policy settings', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $administrator = workspaceMember($tenant, Role::Administrator);

    Config::set('security.two_factor.enforcement', TwoFactorEnforcement::ByPermission->value);
    Config::set('portal.session_idle_minutes', 15);

    $this->actingAs($administrator)
        ->patch(workspaceRoute('workspaces.settings.update', $tenant), [
            'ai_document_overview_enabled' => true,
            'ai_checklist_draft_enabled' => true,
            'ai_reminder_draft_enabled' => true,
            'ai_document_search_enabled' => true,
            'two_factor_enforcement' => TwoFactorEnforcement::AllStaff->value,
            'portal_session_idle_minutes' => 5,
            'default_reminder_interval_days' => 7,
            'retention_archived_days' => 730,
        ])
        ->assertRedirect(workspaceRoute('workspaces.settings.index', $tenant))
        ->assertSessionHasNoErrors();

    $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->sole();

    expect($settings->two_factor_enforcement)->toBe(TwoFactorEnforcement::AllStaff)
        ->and($settings->portal_session_idle_minutes)->toBe(5)
        ->and($settings->default_reminder_interval_days)->toBe(7)
        ->and($settings->retention_archived_days)->toBe(730);
});

test('an empty policy field is stored as inherited rather than as zero', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.update', $tenant), [
            'ai_document_overview_enabled' => true,
            'ai_checklist_draft_enabled' => true,
            'ai_reminder_draft_enabled' => true,
            'ai_document_search_enabled' => true,
            'portal_session_idle_minutes' => '',
            'retention_archived_days' => '',
        ])
        ->assertSessionHasNoErrors();

    $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->sole();

    expect($settings->portal_session_idle_minutes)->toBeNull()
        ->and($settings->retention_archived_days)->toBeNull();
});

test('a firm cannot save a policy weaker than the platform setting', function (array $payload, string $field): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    Config::set('security.two_factor.enforcement', TwoFactorEnforcement::ByPermission->value);
    Config::set('portal.session_idle_minutes', 15);
    Config::set('retention.tenant_bounds.archived_days', ['min' => 365, 'max' => 3650]);

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.update', $tenant), [
            'ai_document_overview_enabled' => true,
            'ai_checklist_draft_enabled' => true,
            'ai_reminder_draft_enabled' => true,
            'ai_document_search_enabled' => true,
            ...$payload,
        ])
        ->assertSessionHasErrors($field);

    expect(TenantSetting::query()->where('tenant_id', $tenant->id)->sole())
        ->two_factor_enforcement->toBeNull()
        ->portal_session_idle_minutes->toBeNull()
        ->retention_archived_days->toBeNull();
})->with([
    'two-factor turned off' => [
        ['two_factor_enforcement' => TwoFactorEnforcement::Off->value],
        'two_factor_enforcement',
    ],
    'portal window lengthened' => [
        ['portal_session_idle_minutes' => 45],
        'portal_session_idle_minutes',
    ],
    'retention beyond the band' => [
        ['retention_archived_days' => 9999],
        'retention_archived_days',
    ],
]);

test('a policy weakened directly in the database is clamped when it is read', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    Config::set('security.two_factor.enforcement', TwoFactorEnforcement::AllStaff->value);
    Config::set('portal.session_idle_minutes', 15);

    // Bypasses the form entirely — the read path is the last line of defence.
    TenantSetting::query()->where('tenant_id', $tenant->id)->update([
        'two_factor_enforcement' => TwoFactorEnforcement::Off->value,
        'portal_session_idle_minutes' => 600,
    ]);

    $this->withoutVite();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.settings.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('settings.security.two_factor_enforcement', TwoFactorEnforcement::AllStaff->value)
            ->where('settings.portal.session_idle_minutes.effective', 15));
});

test('the shared prop carries the assisted-feature flags but not firm policy', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $adviser = workspaceMember($tenant, Role::Adviser);

    $this->withoutVite();

    $this->actingAs($adviser)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('firm_settings.ai')
            ->has('firm_settings.reminders')
            ->missing('firm_settings.security')
            ->missing('firm_settings.retention')
            ->missing('firm_settings.portal'));
});
