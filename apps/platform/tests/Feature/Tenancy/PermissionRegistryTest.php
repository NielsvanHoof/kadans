<?php

declare(strict_types=1);

use App\Audit\Enums\AuditPermission;
use App\Clients\Enums\ClientPermission;
use App\DocumentProcessing\Enums\PipelinePermission;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Support\PermissionRegistry;

it('registers only enums implementing the tenant permission contract', function () {
    foreach (config()->array('tenant-permissions.enums') as $enum) {
        expect(is_a($enum, TenantPermission::class, allow_string: true))->toBeTrue(
            "[$enum] must implement ".TenantPermission::class,
        );
    }
});

it('exposes a unique permission name for every registered permission', function () {
    $values = app(PermissionRegistry::class)->values();

    expect($values)->not->toBeEmpty()
        ->and(array_unique($values))->toHaveCount(count($values));
});

it('rejects two enums that declare the same permission name', function () {
    // DossierPermission is registered twice, so every one of its names collides.
    $registry = new PermissionRegistry([DossierPermission::class, DossierPermission::class]);

    expect(fn () => $registry->all())
        ->toThrow(InvalidArgumentException::class, 'Duplicate permission name');
});

it('rejects an enum that does not implement the contract', function () {
    $registry = new PermissionRegistry([Role::class]);

    expect(fn () => $registry->all())
        ->toThrow(InvalidArgumentException::class, 'does not implement');
});

it('grants owners every registered permission', function () {
    $registry = app(PermissionRegistry::class);

    expect($registry->valuesForRole(Role::Owner))
        ->toEqualCanonicalizing($registry->values());
});

it('never lists owner as a default role, since owners are implicit', function () {
    foreach (app(PermissionRegistry::class)->all() as $permission) {
        expect($permission->defaultRoles())->not->toContain(
            Role::Owner,
            "{$permission->value} must not list Owner explicitly",
        );
    }
});

it('groups every permission under a group that is actually rendered', function () {
    $registry = app(PermissionRegistry::class);
    $grouped = $registry->grouped();

    $flattened = array_merge(...array_values($grouped));

    expect($flattened)->toHaveCount(count($registry->all()));
});

it('resolves a permission back from its stored name', function () {
    $registry = app(PermissionRegistry::class);

    expect($registry->tryFrom('dossiers.view'))->toBe(DossierPermission::View)
        ->and($registry->tryFrom('nope.nope'))->toBeNull()
        ->and($registry->isRegistered('audit.view'))->toBeTrue()
        ->and($registry->isRegistered('nope.nope'))->toBeFalse();
});

/**
 * The intended default capability matrix. Changing any expectation here is a
 * deliberate product decision about what a role may do, not a refactor.
 */
it('gives each role its intended default capabilities', function () {
    $registry = app(PermissionRegistry::class);

    // Administrator runs the firm but does not own its settings.
    expect($registry->valuesForRole(Role::Administrator))
        ->not->toContain(WorkspacePermission::ManageSettings->value)
        ->toContain(AuditPermission::View->value)
        ->toContain(ClientPermission::View->value)
        ->toContain(WorkspacePermission::AssignMemberRoles->value);

    // Adviser does day-to-day dossier work end to end.
    expect($registry->valuesForRole(Role::Adviser))
        ->toContain(DossierPermission::View->value)
        ->toContain(DossierPermission::Create->value)
        ->toContain(DocumentRequestPermission::Review->value)
        ->toContain(UploadedDocumentPermission::Download->value)
        // ...but does not administer the firm.
        ->not->toContain(ClientPermission::View->value)
        ->not->toContain(AuditPermission::View->value)
        ->not->toContain(WorkspacePermission::ViewMembers->value);

    // Reviewer judges submitted work without creating or extracting it.
    expect($registry->valuesForRole(Role::Reviewer))
        ->toContain(DossierPermission::View->value)
        ->toContain(DocumentRequestPermission::Review->value)
        ->toContain(DossierPermission::Complete->value)
        ->not->toContain(DossierPermission::Create->value)
        ->not->toContain(UploadedDocumentPermission::Download->value)
        ->not->toContain(DossierPermission::Export->value);

    // Read-only can look, and nothing else.
    expect($registry->valuesForRole(Role::ReadOnly))
        ->toContain(DossierPermission::View->value)
        ->not->toContain(DossierPermission::Update->value)
        ->not->toContain(DocumentRequestPermission::Review->value)
        ->not->toContain(UploadedDocumentPermission::Download->value);
});

/**
 * Guards the property that motivates the per-action set: reading the processing
 * pipeline and reading the audit log are independently grantable, as are
 * creating a dossier and controlling a client's portal access.
 */
it('keeps independently grantable capabilities on separate permissions', function () {
    $registry = app(PermissionRegistry::class);

    expect(PipelinePermission::View->value)->not->toBe(AuditPermission::View->value);

    // An adviser can act on a processing run without gaining the audit log.
    expect($registry->valuesForRole(Role::Adviser))
        ->toContain(PipelinePermission::Delete->value)
        ->not->toContain(AuditPermission::View->value);

    // Portal access control has its own names, distinct from dossier creation.
    expect($registry->values())
        ->toContain('client_access.grant')
        ->toContain('client_access.revoke')
        ->and(DossierPermission::Create->value)->not->toBe('client_access.revoke');
});
