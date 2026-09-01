<?php

declare(strict_types=1);

use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantSetting;
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
            ->where('settings.ai.reminder_draft', true));
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
        ])
        ->assertRedirect(workspaceRoute('workspaces.settings.index', $tenant));

    $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->first();

    expect($settings)->not->toBeNull()
        ->and($settings->ai_document_overview_enabled)->toBeFalse()
        ->and($settings->ai_checklist_draft_enabled)->toBeFalse()
        ->and($settings->ai_reminder_draft_enabled)->toBeTrue();
});

test('new firms start with all ai features enabled', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->first();

    expect($settings)->not->toBeNull()
        ->and($settings->ai_document_overview_enabled)->toBeTrue()
        ->and($settings->ai_checklist_draft_enabled)->toBeTrue()
        ->and($settings->ai_reminder_draft_enabled)->toBeTrue();
});

test('firm settings are shared with every workspace member', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $administrator = workspaceMember($tenant, Role::Administrator);

    TenantSetting::query()->where('tenant_id', $tenant->id)->update([
        'ai_checklist_draft_enabled' => false,
    ]);

    $this->actingAs($administrator)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('firm_settings.ai.checklist_draft', false)
            ->where('firm_settings.ai.document_overview', true));
});
