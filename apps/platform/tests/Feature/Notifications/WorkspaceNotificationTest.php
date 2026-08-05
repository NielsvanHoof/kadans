<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\ClientPortal\Notifications\ClientQuestionnaireCompletedNotification;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use App\WorkspaceNotifications\Data\WorkspaceNotificationItemData;
use App\WorkspaceNotifications\Queries\FetchWorkspaceNotificationsForUserQuery;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, outsider: User, tenant: mixed, dossier: Dossier}
 */
function createWorkspaceNotificationContext(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $outsider = User::factory()->create();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Client',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => '2025 Payroll dossier',
    ]);

    return [
        'owner' => $owner,
        'outsider' => $outsider,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ];
}

function notifyOwnerOfQuestionnaire(User $owner, mixed $tenant, Dossier $dossier): void
{
    $owner->notify(new ClientQuestionnaireCompletedNotification(
        tenantId: $tenant->id,
        dossierId: $dossier->id,
        dossierTitle: $dossier->title,
        clientName: 'Jane Client',
        message: 'Jane Client finished the questionnaire for “2025 Payroll dossier”.',
        dossierUrl: route('workspaces.dossiers.show', [
            'tenant' => $tenant,
            'dossier' => $dossier,
        ]),
    ));
}

function notifyOwnerOfTranscription(User $owner, mixed $tenant, Dossier $dossier): void
{
    $owner->notify(new AudioTranscriptionCompletedNotification(
        tenantId: $tenant->id,
        audioRecordingId: 1,
        originalFilename: 'call.mp3',
        dossierId: $dossier->id,
        dossierTitle: $dossier->title,
        audioRecordingUrl: route('workspaces.audio-recordings.show', [
            'tenant' => $tenant,
            'audioRecording' => 1,
        ]),
    ));
}

test('shared notifications prop returns tenant-scoped inbox items', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    $this->actingAs($context['owner'])
        ->get(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->missing('notifications')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->where('notifications.unread_count', 1)
                ->has('notifications.items', 1)
                ->where('notifications.items.0.message', 'Jane Client finished the questionnaire for “2025 Payroll dossier”.')
                ->where('notifications.items.0.dossier_id', $context['dossier']->id)
                ->where('notifications.items.0.read_at', null)));
});

test('the notifications query mixes both notification types in one inbox', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);
    notifyOwnerOfTranscription($context['owner'], $context['tenant'], $context['dossier']);

    $notifications = resolve(FetchWorkspaceNotificationsForUserQuery::class)
        ->handle($context['owner'], $context['tenant']);

    $types = array_map(fn (WorkspaceNotificationItemData $item): string => $item->type, $notifications->items);
    $messages = array_map(fn (WorkspaceNotificationItemData $item): string => $item->message, $notifications->items);

    expect($notifications->unreadCount)->toBe(2)
        ->and($notifications->items)->toHaveCount(2)
        ->and($types)->toContain('audio_transcription_completed')
        ->and($types)->toContain('client_questionnaire_completed')
        ->and($messages)->toContain('Transcript ready for call.mp3.')
        ->and($messages)->toContain('Jane Client finished the questionnaire for “2025 Payroll dossier”.');
});

test('staff can mark an audio transcription notification as read', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfTranscription($context['owner'], $context['tenant'], $context['dossier']);

    $notification = $context['owner']->notifications()->sole();

    $this->actingAs($context['owner'])
        ->from(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->post(workspaceRoute('workspaces.notifications.read', $context['tenant'], [
            'notification' => $notification->id,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $context['tenant']));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('staff can mark all workspace notifications as read across both types', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);
    notifyOwnerOfTranscription($context['owner'], $context['tenant'], $context['dossier']);

    expect($context['owner']->unreadNotifications()->count())->toBe(2);

    $this->actingAs($context['owner'])
        ->from(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->post(workspaceRoute('workspaces.notifications.read-all', $context['tenant']))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $context['tenant']));

    expect($context['owner']->unreadNotifications()->count())->toBe(0);
});

test('shared notifications message uses the viewing users locale', function (): void {
    $context = createWorkspaceNotificationContext();
    $context['owner']->update(['locale' => 'nl']);
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    $this->actingAs($context['owner'])
        ->get(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->missing('notifications')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->where('notifications.items.0.message', 'Jane Client heeft de vragenlijst voor “2025 Payroll dossier” afgerond.')));
});

test('staff can mark one workspace notification as read', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    $notification = $context['owner']->notifications()->sole();

    $this->actingAs($context['owner'])
        ->from(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->post(workspaceRoute('workspaces.notifications.read', $context['tenant'], [
            'notification' => $notification->id,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $context['tenant']));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('staff can mark all workspace notifications as read', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    expect($context['owner']->unreadNotifications()->count())->toBe(2);

    $this->actingAs($context['owner'])
        ->from(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->post(workspaceRoute('workspaces.notifications.read-all', $context['tenant']))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $context['tenant']));

    expect($context['owner']->unreadNotifications()->count())->toBe(0);
});

test('staff cannot mark another users notification as read', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    $notification = $context['owner']->notifications()->sole();

    workspaceMember($context['tenant'], Role::ReadOnly, $context['outsider']);

    $this->actingAs($context['outsider'])
        ->post(workspaceRoute('workspaces.notifications.read', $context['tenant'], [
            'notification' => $notification->id,
        ]))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});
