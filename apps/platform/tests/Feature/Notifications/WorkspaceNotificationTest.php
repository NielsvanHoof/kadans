<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\ClientPortal\Notifications\Outreach\ClientPortalInviteNotification;
use App\ClientPortal\Notifications\Outreach\ClientPortalReminderNotification;
use App\ClientPortal\Notifications\Outreach\ClientQuestionnaireCompletedNotification;
use App\ClientPortal\Notifications\Session\ClientPortalNewDeviceNotification;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use App\WorkspaceNotifications\Data\WorkspaceNotificationItemData;
use App\WorkspaceNotifications\Queries\FetchWorkspaceNotificationsForUserQuery;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

function notifyOwnerOfNewDevice(User $owner, mixed $tenant, Dossier $dossier): void
{
    $owner->notify(new ClientPortalNewDeviceNotification(
        tenantId: $tenant->id,
        dossierId: $dossier->id,
        dossierTitle: $dossier->title,
        clientName: 'Jane Client',
        dossierUrl: route('workspaces.dossiers.show', [
            'tenant' => $tenant,
            'dossier' => $dossier,
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

test('inbox timestamps are serialized with a UTC offset', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    // An offset-less string is parsed as local time by the browser, skewing the
    // relative label by the viewer's own offset. Assert the serializer's output
    // rather than a hand-written fixture — that is the seam this bug hid in.
    $this->actingAs($context['owner'])
        ->get(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->where(
                    'notifications.items.0.created_at',
                    fn (string $createdAt): bool => (bool) preg_match(
                        '/T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
                        $createdAt,
                    ),
                )));
});

test('the notifications query mixes all supported notification types in one inbox', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);
    notifyOwnerOfTranscription($context['owner'], $context['tenant'], $context['dossier']);
    notifyOwnerOfNewDevice($context['owner'], $context['tenant'], $context['dossier']);

    $notifications = resolve(FetchWorkspaceNotificationsForUserQuery::class)
        ->handle($context['owner'], $context['tenant']);

    $types = array_map(fn (WorkspaceNotificationItemData $item): string => $item->type, $notifications->items);
    $messages = array_map(fn (WorkspaceNotificationItemData $item): string => $item->message, $notifications->items);

    // New-device rows rebuild the message at read time so the bell follows the
    // viewing user's locale, same as questionnaire and transcription items.
    expect($notifications->unreadCount)->toBe(3)
        ->and($notifications->items)->toHaveCount(3)
        ->and($types)->toContain('audio_transcription_completed')
        ->and($types)->toContain('client_questionnaire_completed')
        ->and($types)->toContain('client_portal_new_device')
        ->and($messages)->toContain('Transcript ready for call.mp3.')
        ->and($messages)->toContain('Jane Client finished the questionnaire for “2025 Payroll dossier”.')
        ->and($messages)->toContain('The portal link for “2025 Payroll dossier” was opened from a new device.');

    $newDevice = collect($notifications->items)
        ->first(fn (WorkspaceNotificationItemData $item): bool => $item->type === 'client_portal_new_device');

    expect($newDevice)->not->toBeNull()
        ->and($newDevice->dossierId)->toBe($context['dossier']->id)
        ->and($newDevice->dossierTitle)->toBe('2025 Payroll dossier')
        ->and($newDevice->clientName)->toBe('Jane Client')
        ->and($newDevice->url)->toContain('/dossiers/');
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

test('staff can delete one workspace notification', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    $notification = $context['owner']->notifications()->sole();

    $this->actingAs($context['owner'])
        ->from(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->delete(workspaceRoute('workspaces.notifications.destroy', $context['tenant'], [
            'notification' => $notification->id,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $context['tenant']));

    expect($context['owner']->notifications()->count())->toBe(0);
});

test('staff can clear all workspace notifications', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);
    notifyOwnerOfTranscription($context['owner'], $context['tenant'], $context['dossier']);
    notifyOwnerOfNewDevice($context['owner'], $context['tenant'], $context['dossier']);

    expect($context['owner']->notifications()->count())->toBe(3);

    $this->actingAs($context['owner'])
        ->from(workspaceRoute('workspaces.dashboard', $context['tenant']))
        ->delete(workspaceRoute('workspaces.notifications.clear-all', $context['tenant']))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $context['tenant']));

    expect($context['owner']->notifications()->count())->toBe(0);
});

test('staff cannot delete another users notification', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    $notification = $context['owner']->notifications()->sole();

    workspaceMember($context['tenant'], Role::ReadOnly, $context['outsider']);

    $this->actingAs($context['outsider'])
        ->delete(workspaceRoute('workspaces.notifications.destroy', $context['tenant'], [
            'notification' => $notification->id,
        ]))
        ->assertNotFound();

    expect($context['owner']->notifications()->count())->toBe(1);
});

test('a non-uuid notification id returns 404 instead of 500', function (string $method, string $routeName): void {
    $context = createWorkspaceNotificationContext();

    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull()
        ->and($route->wheres['notification'] ?? '')->not->toBe('')
        ->and(preg_match('/^'.$route->wheres['notification'].'$/', 'foo'))->toBe(0);

    $this->actingAs($context['owner'])
        ->{$method}(workspaceRoute($routeName, $context['tenant'], [
            'notification' => 'foo',
        ]))
        ->assertNotFound();
})->with([
    'read' => ['post', 'workspaces.notifications.read'],
    'destroy' => ['delete', 'workspaces.notifications.destroy'],
]);

test('an unmapped workspace notification type is omitted from the bell', function (): void {
    $context = createWorkspaceNotificationContext();
    notifyOwnerOfQuestionnaire($context['owner'], $context['tenant'], $context['dossier']);

    // Column type is in the inbox allow-list so the query loads the row; data.type
    // is unknown, which used to render as a questionnaire with dossierId 0.
    $context['owner']->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => ClientQuestionnaireCompletedNotification::DatabaseType,
        'data' => [
            'tenant_id' => (string) $context['tenant']->id,
            'type' => 'unknown_workspace_bell_type',
            'message' => 'should never render',
        ],
    ]);

    $notifications = resolve(FetchWorkspaceNotificationsForUserQuery::class)
        ->handle($context['owner'], $context['tenant']);

    $types = array_map(fn (WorkspaceNotificationItemData $item): string => $item->type, $notifications->items);
    $messages = array_map(fn (WorkspaceNotificationItemData $item): string => $item->message, $notifications->items);

    expect($notifications->items)->toHaveCount(1)
        ->and($types)->toBe(['client_questionnaire_completed'])
        ->and($messages)->not->toContain('should never render')
        ->and($notifications->items[0]->dossierId)->toBe($context['dossier']->id);
});

test('portal mail notifications expose telescope tags for their subjects', function (): void {
    $dossier = new Dossier;
    $dossier->id = 9;

    expect(new ClientPortalInviteNotification(
        grantId: 8,
        dossier: $dossier,
        portalUrl: 'https://example.test/portal',
        expiresAt: Date::now()->addDay(),
        firmName: 'Acme',
    )->tags())->toBe([
        'notification',
        'mail',
        'client_portal_invite',
        'grant:8',
        'dossier:9',
    ])->and(new ClientPortalReminderNotification(
        grantId: 8,
        dossier: $dossier,
        portalUrl: 'https://example.test/portal',
        expiresAt: Date::now()->addDay(),
        firmName: 'Acme',
        source: DossierReminderSource::Manual,
    )->tags())->toContain('client_portal_reminder', 'grant:8', 'dossier:9', 'reminder_source:manual');
});
