<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Listeners\LogClientPortalReminderSent;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\ClientPortalReminderNotification;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('dossier follow up details can be assigned only to active workspace staff', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $adviser = workspaceMember($tenant, Role::Adviser);
    $foreignUser = User::factory()->create();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.dossiers.update', $tenant, ['dossier' => $dossier]), [
            'title' => $dossier->title,
            'reference' => $dossier->reference,
            'due_date' => '2026-08-15',
            'responsible_user_id' => $adviser->id,
            'reminder_interval_days' => 3,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]));

    $updatedDossier = $dossier->fresh();

    expect($updatedDossier->due_date?->toDateString())->toBe('2026-08-15')
        ->and($updatedDossier->responsible_user_id)->toBe($adviser->id)
        ->and($updatedDossier->reminder_interval_days)->toBe(3);

    $this->patch(workspaceRoute('workspaces.dossiers.update', $tenant, ['dossier' => $dossier]), [
        'title' => $dossier->title,
        'reference' => $dossier->reference,
        'responsible_user_id' => $foreignUser->id,
    ])->assertSessionHasErrors('responsible_user_id');
});

test('dashboard and dossier index expose operational workflow queues', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $needsReview = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::InReview,
    ]);
    $awaitingClient = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'due_date' => today()->addWeek(),
    ]);
    $overdue = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Overdue payroll',
        'due_date' => today()->subDay(),
    ]);

    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $needsReview->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $awaitingClient->id,
        'status' => DocumentRequestStatus::Pending,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $overdue->id,
        'status' => DocumentRequestStatus::Rejected,
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('metrics.needs_review', 1)
            ->where('metrics.awaiting_client', 2)
            ->where('metrics.overdue', 1));

    $this->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?filter[queue]=overdue')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.title', 'Overdue payroll'));
});

test('staff can queue an audited reminder with a fresh portal grant', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
        'reminder_interval_days' => 3,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.reminders.store', $tenant, [
            'dossier' => $dossier,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, [
            'dossier' => $dossier,
        ]));

    $grant = ClientAccessGrant::query()->sole();
    $notification = null;

    Notification::assertSentOnDemand(
        ClientPortalReminderNotification::class,
        function (ClientPortalReminderNotification $sentNotification) use (&$notification): bool {
            $notification = $sentNotification;

            return true;
        },
    );

    expect($dossier->fresh()->next_reminder_at?->isFuture())->toBeTrue();

    $queuedActivity = Activity::query()
        ->where('event', AuditEvent::DossierReminderQueued->value)
        ->sole();

    expect($queuedActivity->subject_id)->toBe($dossier->id)
        ->and($queuedActivity->causer_id)->toBe($owner->id)
        ->and($queuedActivity->getProperty('source'))->toBe(DossierReminderSource::Manual->value)
        ->and((string) json_encode($queuedActivity->properties))->not->toContain($grant->token)
        ->and($notification)
        ->toBeInstanceOf(ClientPortalReminderNotification::class);
    assert($notification instanceof ClientPortalReminderNotification);

    resolve(LogClientPortalReminderSent::class)->handle(
        new NotificationSent(new AnonymousNotifiable, $notification, 'mail'),
    );

    expect($dossier->fresh()->last_client_message_sent_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('event', AuditEvent::DossierReminderSent->value)
            ->where('subject_id', $dossier->id)
            ->exists())->toBeTrue();
});

test('a custom reminder message replaces the default notification body', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.reminders.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'message' => 'Hi Jane, quick nudge about your paperwork.',
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, [
            'dossier' => $dossier,
        ]));

    Notification::assertSentOnDemand(
        ClientPortalReminderNotification::class,
        function (
            ClientPortalReminderNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            $mail = $notification->toMail($notifiable);

            expect((string) json_encode($mail->introLines))
                ->toContain('Hi Jane, quick nudge about your paperwork.')
                ->not->toContain('is still waiting for information');

            return true;
        },
    );

    $queuedActivity = Activity::query()
        ->where('event', AuditEvent::DossierReminderQueued->value)
        ->sole();

    expect($queuedActivity->getProperty('custom_message'))->toBeTrue();
});

test('scheduled reminders process only due dossiers with client work remaining', function (): void {
    Notification::fake();

    ['tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dueDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
        'reminder_interval_days' => 2,
        'next_reminder_at' => now()->subMinute(),
    ]);
    $laterDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
        'reminder_interval_days' => 2,
        'next_reminder_at' => now()->addDay(),
    ]);

    foreach ([$dueDossier, $laterDossier] as $dossier) {
        DocumentRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'status' => DocumentRequestStatus::Pending,
        ]);
    }

    $this->artisan('dossiers:send-reminders')->assertSuccessful();

    Notification::assertCount(1);
    expect(ClientAccessGrant::query()->whereBelongsTo($dueDossier)->count())->toBe(1)
        ->and(ClientAccessGrant::query()->whereBelongsTo($laterDossier)->count())->toBe(0)
        ->and(Activity::query()
            ->where('event', AuditEvent::DossierReminderQueued->value)
            ->where('subject_id', $dueDossier->id)
            ->whereNull('causer_id')
            ->exists())->toBeTrue();
});

test('scheduled reminders that fail validation disable further sends and log the reason', function (): void {
    Notification::fake();

    ['tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
        'reminder_interval_days' => 2,
        'next_reminder_at' => now()->subMinute(),
    ]);

    // Optional pending items match the schedule query but are not "outstanding",
    // so SendDossierReminderAction rejects with ValidationException.
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
        'configuration' => ['is_required' => false],
    ]);

    Log::spy();

    $this->artisan('dossiers:send-reminders')->assertSuccessful();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduled dossier reminder disabled after validation failure.'
            && ($context['dossier_id'] ?? null) === $dossier->id);

    $dossier->refresh();

    expect($dossier->next_reminder_at)->toBeNull()
        ->and($dossier->reminder_interval_days)->toBeNull();

    Notification::assertNothingSent();
});

test('portal reports progress and records the latest client open', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'due_date' => today()->addWeek(),
    ]);
    $pending = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Bank statement',
        'status' => DocumentRequestStatus::Pending,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Accepted,
    ]);
    $grant = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    $this->get(route('portal.exchange', $grant['plain_text_token']))
        ->assertRedirect(route('portal.show'))
        ->assertSessionHas(ResolveClientPortalGrant::SESSION_GRANT_ID);

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('dossier.progress.total', 3)
            ->where('dossier.progress.completed', 2)
            ->where('dossier.progress.remaining', 1)
            ->where('dossier.progress.next_incomplete.id', $pending->id)
            ->where('dossier.progress.next_incomplete.title', 'Bank statement'));

    expect($dossier->fresh()->last_client_opened_at)->not->toBeNull();
});

test('read only members cannot send client reminders', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $this->actingAs($reader)
        ->post(workspaceRoute('workspaces.dossiers.reminders.store', $tenant, [
            'dossier' => $dossier,
        ]))
        ->assertForbidden();
});

test('archiving a dossier cancels its scheduled reminder', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'reminder_interval_days' => 3,
        'next_reminder_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.dossiers.destroy', $tenant, [
            'dossier' => $dossier,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.dossiers.index', $tenant));

    expect(Dossier::withTrashed()->findOrFail($dossier->id)->next_reminder_at)->toBeNull();
});
