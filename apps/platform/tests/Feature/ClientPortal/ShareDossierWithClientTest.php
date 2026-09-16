<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\Access\ShareDossierWithClientAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Enums\DossierStatus;
use App\Tenancy\Enums\Role;
use Illuminate\Validation\ValidationException;

covers(ShareDossierWithClientAction::class);

/*
 * Publishing a dossier, separated from minting a link.
 *
 * Before client-level access these were the same act: issuing a per-dossier
 * grant was the only thing that moved a dossier out of draft. A standing link
 * is a door, so deciding what sits behind it had to become its own step.
 */

test('sharing moves a draft dossier in front of the client without minting a link', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $before = ClientAccessGrant::query()->count();

    resolve(ShareDossierWithClientAction::class)->handle($context['draft'], $context['owner']);

    expect($context['draft']->fresh()->status)->toBe(DossierStatus::AwaitingClient)
        ->and(ClientAccessGrant::query()->count())->toBe($before);
});

test('sharing records who opened the dossier up and which links gained reach', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    resolve(ShareDossierWithClientAction::class)->handle($context['draft'], $context['owner']);

    $activity = Activity::query()
        ->where('event', AuditEvent::DossierSharedWithClient->value)
        ->where('subject_id', $context['draft']->id)
        ->firstOrFail();

    // The record that answers "what could see this dossier, and from when".
    expect($activity->getProperty('client_id'))->toBe($context['client']->id)
        ->and($activity->getProperty('client_access_grant_ids'))
        ->toContain($context['grant']->id);
});

test('sharing an already shared dossier changes nothing and adds no audit noise', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    resolve(ShareDossierWithClientAction::class)->handle($context['shared'], $context['owner']);

    expect(Activity::query()
        ->where('event', AuditEvent::DossierSharedWithClient->value)
        ->where('subject_id', $context['shared']->id)
        ->exists())->toBeFalse();
});

test('a completed dossier cannot be shared again', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $context['shared']->forceFill(['status' => DossierStatus::Completed])->save();

    expect(fn () => resolve(ShareDossierWithClientAction::class)
        ->handle($context['shared']->fresh(), $context['owner']))
        ->toThrow(ValidationException::class);
});

test('staff can share a dossier from the dossier page', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);

    $this->actingAs($context['owner'])
        ->post(workspaceRoute('workspaces.dossiers.share.store', $context['tenant'], [
            'dossier' => $context['draft']->id,
        ]))
        ->assertRedirect();

    expect($context['draft']->fresh()->status)->toBe(DossierStatus::AwaitingClient);
});

test('a member without the grant permission cannot share a dossier', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $readOnly = workspaceMember($context['tenant'], Role::ReadOnly);

    // Staff denials are 403 and audited — the concealed 404 is a portal thing,
    // and this is a workspace route.
    $this->actingAs($readOnly)
        ->post(workspaceRoute('workspaces.dossiers.share.store', $context['tenant'], [
            'dossier' => $context['draft']->id,
        ]))
        ->assertForbidden();

    expect($context['draft']->fresh()->status)->toBe(DossierStatus::Draft);
});
