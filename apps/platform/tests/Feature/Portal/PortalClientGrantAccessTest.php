<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\Access\ShareDossierWithClientAction;
use App\ClientPortal\Queries\Access\FetchReachableDossiersQuery;
use App\ClientPortal\Queries\Access\ResolveGrantedDossierQuery;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\TenantContext;

covers(ResolveGrantedDossierQuery::class, FetchReachableDossiersQuery::class);

/*
 * What one client-level link may and may not open.
 *
 * Every refusal here has to be a 404 and has to leave the audit trail alone.
 * Portal denials conceal whether an id exists at all, and a client probing
 * dossier ids must not be able to fill the trail while doing it — both are
 * standing rules in .ai/rules/client-portal.md, and both are easy to lose by
 * accident when the check moves, which is exactly what this change did.
 */

/** Open a portal session and return the document request id to aim at. */
function portalItemOn(Dossier $dossier, int $tenantId): DocumentRequest
{
    return DocumentRequest::factory()->createOne([
        'tenant_id' => $tenantId,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::Text,
        'status' => DocumentRequestStatus::Pending,
    ]);
}

test('a client link opens every dossier the firm has shared with that client', function (): void {
    $context = createPortalClientWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.dossiers.show', ['dossier' => $context['shared']->id]))
        ->assertOk();

    $this->get(route('portal.dossiers.show', ['dossier' => $context['secondShared']->id]))
        ->assertOk();
});

test('a client link does not open a dossier the firm has not shared yet', function (): void {
    $context = createPortalClientWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Draft is the whole security boundary: a dossier is invisible until a
    // member with client_access.grant deliberately shares it.
    $this->get(route('portal.dossiers.show', ['dossier' => $context['draft']->id]))
        ->assertNotFound();
});

test('a dossier shared after the link was sent becomes reachable by it', function (): void {
    $context = createPortalClientWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));
    $this->get(route('portal.dossiers.show', ['dossier' => $context['draft']->id]))
        ->assertNotFound();

    actingInWorkspace($context['tenant']);
    resolve(ShareDossierWithClientAction::class)
        ->handle($context['draft'], $context['owner']);

    $this->get(route('portal.dossiers.show', ['dossier' => $context['draft']->id]))
        ->assertOk();
});

test('a client link still opens a completed dossier, read-only', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $context['shared']->forceFill(['status' => DossierStatus::Completed])->save();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Deliberately reachable. Dropping it out of reach the moment staff finish
    // would sign a client out mid-questionnaire, which is the failure the
    // sliding session window exists to prevent. canSubmit() stops the writes.
    $this->get(route('portal.dossiers.show', ['dossier' => $context['shared']->id]))
        ->assertOk();
});

test('a client link does not open an archived dossier', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $context['shared']->delete();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.dossiers.show', ['dossier' => $context['shared']->id]))
        ->assertNotFound();
});

test('a client link does not open another client dossier in the same firm', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $otherClient = Client::factory()->create(['tenant_id' => $context['tenant']->id]);
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'client_id' => $otherClient->id,
        'status' => DossierStatus::AwaitingClient,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.dossiers.show', ['dossier' => $otherDossier->id]))
        ->assertNotFound();
});

test('a client link does not open a dossier in another firm', function (): void {
    $context = createPortalClientWithGrant();

    ['owner' => $otherOwner, 'tenant' => $otherTenant] = provisionWorkspace('Rival Accountants');

    $foreignDossier = resolve(TenantContext::class)->runForTenant(
        $otherTenant,
        function () use ($otherTenant, $otherOwner): Dossier {
            $client = Client::factory()->create(['tenant_id' => $otherTenant->id]);

            return Dossier::factory()->create([
                'tenant_id' => $otherTenant->id,
                'client_id' => $client->id,
                'responsible_user_id' => $otherOwner->id,
                'status' => DossierStatus::AwaitingClient,
            ]);
        },
    );

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.dossiers.show', ['dossier' => $foreignDossier->id]))
        ->assertNotFound();
});

test('every write endpoint refuses an unreachable dossier with a concealed 404', function (
    string $describe,
): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $draftItem = portalItemOn($context['draft'], $context['tenant']->id);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $before = Activity::query()->count();

    match ($describe) {
        'answer' => $this->post(
            route('portal.document-requests.answer', ['documentRequest' => $draftItem->id]),
            ['answer_text' => 'Keizersgracht 1'],
        )->assertNotFound(),
        'upload ticket' => $this->postJson(
            route('portal.upload-tickets.store'),
            [
                'dossier_id' => $context['draft']->id,
                'filename' => 'statement.pdf',
                'content_type' => 'application/pdf',
                'size_bytes' => 1024,
            ],
        )->assertNotFound(),
    };

    // A concealed refusal must stay out of the trail, or probing dossier ids
    // becomes a way to flood it.
    expect(Activity::query()->count())->toBe($before)
        ->and(Activity::query()->where('event', AuditEvent::AccessDenied->value)->exists())->toBeFalse();
})->with(['answer', 'upload ticket']);

test('an upload ticket cannot be minted for a dossier the link does not open', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $otherClient = Client::factory()->create(['tenant_id' => $context['tenant']->id]);
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'client_id' => $otherClient->id,
        'status' => DossierStatus::AwaitingClient,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Without the dossier_id check this would sign a PUT into another client's
    // staging prefix — the single most easily missed hole in the whole change.
    $this->postJson(route('portal.upload-tickets.store'), [
        'dossier_id' => $otherDossier->id,
        'filename' => 'statement.pdf',
        'content_type' => 'application/pdf',
        'size_bytes' => 1024,
    ])->assertNotFound();
});

test('a dossier link still opens only its own dossier', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $sibling = Dossier::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'client_id' => $context['client']->id,
        'status' => DossierStatus::AwaitingClient,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.dossiers.show', ['dossier' => $context['dossier']->id]))
        ->assertOk();

    // Same client, shared status — and still refused, because the grant names
    // one dossier and scope is checked before anything else.
    $this->get(route('portal.dossiers.show', ['dossier' => $sibling->id]))
        ->assertNotFound();
});
