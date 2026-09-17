<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\Access\CreateClientAccessGrantAction;
use App\ClientPortal\Actions\Outreach\NotifyWorkspaceIfQuestionnaireCompleteAction;
use App\ClientPortal\Events\Outreach\ClientQuestionnaireCompleted;
use App\ClientPortal\Notifications\Outreach\ClientQuestionnaireCompletedNotification;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{
 *     owner: User,
 *     tenant: mixed,
 *     client: Client,
 *     dossier: Dossier,
 *     plainTextToken: string
 * }
 */
function createQuestionnaireNotificationContext(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Client',
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => '2025 Payroll dossier',
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'client' => $client,
        'dossier' => $dossier->fresh(),
        'plainTextToken' => $result['plain_text_token'],
    ];
}

test('last portal answer submission broadcasts questionnaire completed to the workspace', function (): void {
    Event::fake([ClientQuestionnaireCompleted::class]);

    $context = createQuestionnaireNotificationContext();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $documentRequest,
    ]), [
        'answer_text' => '221B Baker Street',
    ])->assertRedirect(route('portal.dossiers.show', ['dossier' => $context['dossier']->id]));

    Event::assertDispatched(function (ClientQuestionnaireCompleted $event) use ($context): bool {
        expect($event->tenantSlug)->toBe($context['tenant']->slug)
            ->and($event->dossierId)->toBe($context['dossier']->id)
            ->and($event->dossierTitle)->toBe('2025 Payroll dossier')
            ->and($event->clientName)->toBe('Jane Client')
            ->and($event->message)->toContain('Jane Client')
            ->and($event->dossierUrl)->toContain($context['tenant']->slug);

        return true;
    });

    expect(
        Activity::query()
            ->where('event', AuditEvent::ClientQuestionnaireCompleted->value)
            ->where('subject_id', $context['dossier']->id)
            ->exists(),
    )->toBeTrue();
});

test('partial portal submission does not broadcast questionnaire completed', function (): void {
    Event::fake([ClientQuestionnaireCompleted::class]);

    $context = createQuestionnaireNotificationContext();

    actingInWorkspace($context['tenant']);
    $first = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Address',
        'status' => DocumentRequestStatus::Pending,
        'sort_order' => 0,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Boolean,
        'title' => 'Self employed?',
        'status' => DocumentRequestStatus::Pending,
        'sort_order' => 1,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $first,
    ]), [
        'answer_text' => '221B Baker Street',
    ])->assertRedirect(route('portal.dossiers.show', ['dossier' => $context['dossier']->id]));

    Event::assertNotDispatched(ClientQuestionnaireCompleted::class);

    expect(
        Activity::query()
            ->where('event', AuditEvent::ClientQuestionnaireCompleted->value)
            ->exists(),
    )->toBeFalse();
});

test('empty dossier does not broadcast questionnaire completed', function (): void {
    Event::fake([ClientQuestionnaireCompleted::class]);

    $context = createQuestionnaireNotificationContext();

    actingInWorkspace($context['tenant']);

    resolve(NotifyWorkspaceIfQuestionnaireCompleteAction::class)->handle($context['dossier']);

    Event::assertNotDispatched(ClientQuestionnaireCompleted::class);
});

test('resubmitting after rejection broadcasts questionnaire completed again', function (): void {
    Event::fake([ClientQuestionnaireCompleted::class]);

    $context = createQuestionnaireNotificationContext();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Rejected,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $documentRequest,
    ]), [
        'answer_text' => 'Updated address',
    ])->assertRedirect(route('portal.dossiers.show', ['dossier' => $context['dossier']->id]));

    Event::assertDispatched(ClientQuestionnaireCompleted::class);
});

test('last portal submission creates database notifications for active members only', function (): void {
    Event::fake([ClientQuestionnaireCompleted::class]);

    $context = createQuestionnaireNotificationContext();
    $activeMember = workspaceMember($context['tenant'], Role::Adviser);
    $suspendedMember = workspaceMember($context['tenant'], Role::ReadOnly);
    $outsider = User::factory()->create();

    TenantMembership::query()
        ->where('tenant_id', $context['tenant']->id)
        ->where('user_id', $suspendedMember->id)
        ->update(['status' => TenantMembershipStatus::Suspended]);

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $documentRequest,
    ]), [
        'answer_text' => '221B Baker Street',
    ])->assertRedirect(route('portal.dossiers.show', ['dossier' => $context['dossier']->id]));

    expect($context['owner']->fresh()->notifications)->toHaveCount(1)
        ->and($activeMember->fresh()->notifications)->toHaveCount(1)
        ->and($suspendedMember->fresh()->notifications)
        ->toBeEmpty()
        ->and($outsider->fresh()->notifications)
        ->toBeEmpty();

    $payload = $context['owner']->fresh()->notifications->first()->data;

    expect($payload['type'])->toBe('client_questionnaire_completed')
        ->and($payload['tenant_id'])->toBe((string) $context['tenant']->id)
        ->and($payload['dossier_id'])->toBe($context['dossier']->id)
        ->and($payload['client_name'])->toBe('Jane Client')
        ->and($payload['message'])->toContain('Jane Client')
        ->and($payload['dossier_url'])->toContain($context['tenant']->slug);
});

test('partial portal submission does not create database notifications', function (): void {
    Event::fake([ClientQuestionnaireCompleted::class]);
    Notification::fake();

    $context = createQuestionnaireNotificationContext();

    actingInWorkspace($context['tenant']);
    $first = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Address',
        'status' => DocumentRequestStatus::Pending,
        'sort_order' => 0,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Boolean,
        'title' => 'Self employed?',
        'status' => DocumentRequestStatus::Pending,
        'sort_order' => 1,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $first,
    ]), [
        'answer_text' => '221B Baker Street',
    ])->assertRedirect(route('portal.dossiers.show', ['dossier' => $context['dossier']->id]));

    Notification::assertNothingSent();
});

/*
 * The bell is driven by an Echo handler that calls
 * router.reload({ only: ['notifications'] }), so the broadcast is an instruction
 * to go and read the database. Counting the rows from inside a real listener is
 * the only way to assert the two are in the right order — counting after the
 * request passes either way. TASK-64.
 */
test('inbox rows exist by the time the questionnaire completed broadcast is dispatched', function (): void {
    $context = createQuestionnaireNotificationContext();
    workspaceMember($context['tenant'], Role::Adviser);

    $rowsAtDispatch = null;

    Event::listen(ClientQuestionnaireCompleted::class, function () use (&$rowsAtDispatch): void {
        // First dispatch only — a later, correctly ordered one must not be able
        // to paper over an earlier one that fired before the rows existed.
        $rowsAtDispatch ??= DB::table('notifications')
            ->where('type', ClientQuestionnaireCompletedNotification::DatabaseType)
            ->count();
    });

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $documentRequest,
    ]), [
        'answer_text' => '221B Baker Street',
    ])->assertRedirect(route('portal.dossiers.show', ['dossier' => $context['dossier']->id]));

    // Owner plus the adviser, both already written when the bell was told to look.
    expect($rowsAtDispatch)->toBe(2);
});

test('a rolled back submission leaves no inbox rows behind', function (): void {
    Event::fake([ClientQuestionnaireCompleted::class]);

    $context = createQuestionnaireNotificationContext();

    actingInWorkspace($context['tenant']);
    DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Submitted,
    ]);

    // Un-queued notifications join the caller's transaction, so a submission
    // that fails after the notify call must take the rows with it.
    try {
        DB::transaction(function () use ($context): void {
            resolve(NotifyWorkspaceIfQuestionnaireCompleteAction::class)->handle($context['dossier']);

            throw new RuntimeException('Submission failed after notifying.');
        });
    } catch (RuntimeException) {
        // Expected — the rollback is what is under test.
    }

    expect(
        DB::table('notifications')
            ->where('type', ClientQuestionnaireCompletedNotification::DatabaseType)
            ->count(),
    )->toBe(0);

    // The broadcast half of this is carried by ClientQuestionnaireCompleted's
    // $afterCommit and is not asserted here: QueueFake records pushes as they
    // are made and ignores after-commit deferral, so it reports a BroadcastEvent
    // that the real queue would never have sent.
});
