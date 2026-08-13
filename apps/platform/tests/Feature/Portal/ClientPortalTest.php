<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\ClientPortal\Actions\ResolveClientAccessGrantFromTokenAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Listeners\LogClientPortalInviteFailed;
use App\ClientPortal\Listeners\LogClientPortalInviteSent;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\ClientPortalInviteNotification;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Pest\Expectation;

/**
 * @return array{owner: User, tenant: mixed, client: Client, dossier: Dossier, plainTextToken: string, grant: ClientAccessGrant}
 */
function createPortalDossierWithGrant(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => '2025 Payroll dossier',
        'status' => DossierStatus::Draft,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'client' => $client,
        'dossier' => $dossier->fresh(),
        'plainTextToken' => $result['plain_text_token'],
        'grant' => $result['grant'],
    ];
}

test('guest exchanges a valid access token for a restricted portal session', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Payslip January 2025',
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'))
        ->assertSessionHas(ResolveClientPortalGrant::SESSION_GRANT_ID, $context['grant']->id)
        ->assertSessionHas(ResolveClientPortalGrant::SESSION_EXPIRES_AT);

    expect((string) json_encode(session()->all()))
        ->not->toContain($context['plainTextToken']);

    $response = $this->get(route('portal.show'));

    $response
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertHeader('Expires', '0')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/show')
            ->where('firm.name', 'Acme Accountants')
            ->where('dossier.title', '2025 Payroll dossier')
            ->where('dossier.client.name', $context['client']->name)
            ->missing('token')
            ->has('dossier.document_requests', 1)
            ->tap(fn (Assert $page): Expectation => expect($page->toArray())
                ->toHaveKey('encryptHistory', true)));

    // The portal inherits the app-wide policy; its own middleware only keeps the
    // stricter Referrer-Policy asserted above. One header, not two.
    expect($response->headers->all('Content-Security-Policy'))->toHaveCount(1);
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain('script-src')
        ->and($context['grant']->fresh()->last_used_at)->not->toBeNull();

    $activity = Activity::query()
        ->where('event', AuditEvent::ClientPortalAccessed->value)
        ->sole();

    expect($activity->subject_id)->toBe($context['grant']->id)
        ->and($activity->getProperty('source'))->toBe('client_portal')
        ->and((string) json_encode($activity->properties))->not->toContain($context['plainTextToken']);
});

test('invalid or revoked portal tokens are rejected', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', 'not-a-real-token'))
        ->assertNotFound()
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'));

    actingInWorkspace($context['tenant']);
    $context['grant']->update(['revoked_at' => now()]);

    $this->get(route('portal.show'))
        ->assertNotFound()
        ->assertSessionMissing(ResolveClientPortalGrant::SESSION_GRANT_ID);

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertNotFound()
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

test('portal routes require a live restricted session', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.show'))->assertNotFound();

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'));

    $this->travel(16)->minutes();

    $this->get(route('portal.show'))
        ->assertNotFound()
        ->assertSessionMissing(ResolveClientPortalGrant::SESSION_GRANT_ID)
        ->assertSessionMissing(ResolveClientPortalGrant::SESSION_EXPIRES_AT);
});

test('guest can upload a document through the portal', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Payslip January 2025',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $file = UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf');

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(
        route('portal.document-requests.upload', [
            'documentRequest' => $documentRequest->id,
        ]),
        ['documents' => [$file]],
    )->assertRedirect(route('portal.show'));

    actingInWorkspace($context['tenant']);

    $documentRequest->refresh();

    expect($documentRequest->status)->toBe(DocumentRequestStatus::Submitted)
        ->and(UploadedDocument::query()->where('document_request_id', $documentRequest->id)->exists())->toBeTrue()
        ->and($context['dossier']->fresh()->status)->toBe(DossierStatus::InReview);
});

test('portal cannot upload while a file item awaits review', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Payslip January 2025',
        'status' => DocumentRequestStatus::Submitted,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(
        route('portal.document-requests.upload', [
            'documentRequest' => $documentRequest->id,
        ]),
        ['documents' => [UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf')]],
    )->assertForbidden();

    actingInWorkspace($context['tenant']);

    expect(UploadedDocument::query()->where('document_request_id', $documentRequest->id)->exists())->toBeFalse();
});

test('portal cannot answer while a text item awaits review', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Submitted,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $documentRequest,
    ]), [
        'answer_text' => '221B Baker Street',
    ])->assertForbidden();

    $documentRequest->refresh();

    expect($documentRequest->answer_text)->toBeNull();
});

test('a saved choice answer reveals the matching conditional field on the portal', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $sourceFieldKey = (string) Str::ulid();
    $dependentFieldKey = (string) Str::ulid();
    $yesOptionId = (string) Str::uuid();
    $noOptionId = (string) Str::uuid();

    $choiceRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Choice,
        'title' => 'Do you have employees?',
        'field_key' => $sourceFieldKey,
        'sort_order' => 0,
        'configuration' => [
            'options' => [
                ['id' => $yesOptionId, 'label' => 'Yes'],
                ['id' => $noOptionId, 'label' => 'No'],
            ],
            'is_required' => true,
        ],
    ]);
    $dependentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'How many employees do you have?',
        'field_key' => $dependentFieldKey,
        'sort_order' => 1,
        'configuration' => [
            'is_required' => true,
            'visibility' => [
                'source_field_key' => $sourceFieldKey,
                'option_ids' => [$yesOptionId],
            ],
        ],
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossier.document_requests', 1)
            ->where('dossier.document_requests.0.id', $choiceRequest->id));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $choiceRequest,
    ]), [
        'answer_option_ids' => [$yesOptionId],
    ])->assertRedirect(route('portal.show'));

    $choiceRequest->refresh();

    expect($choiceRequest)
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->answer_option_ids->toBe([$yesOptionId]);

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossier.document_requests', 2)
            ->where('dossier.document_requests.1.id', $dependentRequest->id));
});

test('portal upload cannot target a document request from another dossier', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'client_id' => $context['client']->id,
    ]);
    $foreignRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $otherDossier->id,
        'title' => 'Foreign request',
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(
        route('portal.document-requests.upload', [
            'documentRequest' => $foreignRequest->id,
        ]),
        ['documents' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]],
    )->assertNotFound();
});

test('portal upload rolls back database and storage when its audit record fails', function (): void {
    Storage::fake('local');

    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Payslip January 2025',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $event = 'eloquent.creating: '.Activity::class;
    Event::listen($event, static function (Activity $activity): void {
        throw_if($activity->event === AuditEvent::DocumentUploaded->value, RuntimeException::class, 'Simulated audit failure.');
    });

    $this->get(route('portal.exchange', $context['plainTextToken']));

    try {
        $this->post(
            route('portal.document-requests.upload', [
                'documentRequest' => $documentRequest->id,
            ]),
            ['documents' => [UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf')]],
        )->assertServerError();
    } finally {
        Event::forget($event);
    }

    actingInWorkspace($context['tenant']);

    $documentRequest->refresh();

    expect($documentRequest->status)->toBe(DocumentRequestStatus::Pending)
        ->and(UploadedDocument::query()->exists())->toBeFalse()
        ->and($context['dossier']->fresh()->status)->toBe(DossierStatus::AwaitingClient)
        ->and($context['grant']->fresh()->last_used_at)->toBeNull()
        ->and(Storage::disk('local')->allFiles())
        ->toBeEmpty();
});

test('portal answer rolls back when its audit record fails', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $event = 'eloquent.creating: '.Activity::class;
    Event::listen($event, static function (Activity $activity): void {
        throw_if($activity->event === AuditEvent::QuestionnaireAnswerSubmitted->value, RuntimeException::class, 'Simulated audit failure.');
    });

    $this->get(route('portal.exchange', $context['plainTextToken']));

    try {
        $this->post(route('portal.document-requests.answer', [
            'documentRequest' => $documentRequest,
        ]), [
            'answer_text' => '221B Baker Street',
        ])->assertServerError();
    } finally {
        Event::forget($event);
    }

    $documentRequest->refresh();

    expect($documentRequest->status)->toBe(DocumentRequestStatus::Pending)
        ->and($documentRequest->answer_text)->toBeNull()
        ->and($context['dossier']->fresh()->status)->toBe(DossierStatus::AwaitingClient)
        ->and($context['grant']->fresh()->last_used_at)->toBeNull();
});

test('staff can email a portal invite when creating an access grant', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.access-grants.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'expires_in_days' => 7,
            'send_invite' => true,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertSessionHas('access_grant_token')
        ->assertSessionHas('access_grant_portal_url');

    $grant = ClientAccessGrant::query()->sole();

    Notification::assertSentOnDemand(
        ClientPortalInviteNotification::class,
        function (
            ClientPortalInviteNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ) use ($grant): bool {
            $queuedJob = new SendQueuedNotifications($notifiable, $notification, $channels);

            expect($notification)->toBeInstanceOf(ShouldBeEncrypted::class)
                ->and($notification->afterCommit)->toBeTrue()
                ->and($notification->grantId)->toBe($grant->id)
                ->and($notification->locale)->toBe(app()->getLocale())
                ->and($queuedJob->afterCommit)->toBeTrue()
                ->and($queuedJob->shouldBeEncrypted)->toBeTrue();

            return true;
        },
    );

    expect($dossier->fresh()->status)->toBe(DossierStatus::AwaitingClient);

    $auditEvents = Activity::query()
        ->whereIn('event', [
            AuditEvent::ClientPortalAccessGrantCreated->value,
            AuditEvent::ClientPortalInviteQueued->value,
        ])
        ->pluck('event');

    expect($auditEvents)->toContain(
        AuditEvent::ClientPortalAccessGrantCreated->value,
        AuditEvent::ClientPortalInviteQueued->value,
    );

    $plainTextToken = (string) session('access_grant_token');
    $auditPayload = (string) json_encode(
        Activity::query()
            ->whereIn('event', $auditEvents)
            ->get()
            ->pluck('properties')
            ->all(),
    );

    expect($plainTextToken)->not->toBeEmpty()
        ->and($auditPayload)->not->toContain($plainTextToken)
        ->and($auditPayload)->not->toContain('/portal/');
});

test('staff can create a portal link without emailing', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.access-grants.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'expires_in_days' => 7,
            'send_invite' => false,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]));

    Notification::assertNothingSent();

    expect(Activity::query()
        ->where('event', AuditEvent::ClientPortalAccessGrantCreated->value)
        ->exists())->toBeTrue()
        ->and(Activity::query()
            ->where('event', AuditEvent::ClientPortalInviteQueued->value)
            ->exists())->toBeFalse();
});

test('revoking portal access is audited atomically', function (): void {
    $context = createPortalDossierWithGrant();

    $this->actingAs($context['owner'])
        ->delete(workspaceRoute('workspaces.access-grants.destroy', $context['tenant'], [
            'grant' => $context['grant'],
        ]))
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]));

    expect($context['grant']->fresh()->revoked_at)->not->toBeNull();

    $activity = Activity::query()
        ->where('event', AuditEvent::ClientPortalAccessGrantRevoked->value)
        ->sole();

    expect($activity->subject_id)->toBe($context['grant']->id)
        ->and($activity->causer_id)->toBe($context['owner']->id);
});

test('portal invitation delivery outcomes are audited without the bearer url', function (): void {
    $context = createPortalDossierWithGrant();

    $notification = new ClientPortalInviteNotification(
        grantId: $context['grant']->id,
        dossier: $context['dossier'],
        portalUrl: route('portal.exchange', $context['plainTextToken']),
        expiresAt: $context['grant']->expires_at,
        firmName: $context['tenant']->name,
    );
    $notifiable = (new AnonymousNotifiable)->route('mail', $context['client']->email);

    resolve(LogClientPortalInviteSent::class)->handle(
        new NotificationSent($notifiable, $notification, 'mail'),
    );
    resolve(LogClientPortalInviteFailed::class)->handle(
        new NotificationFailed($notifiable, $notification, 'mail', [
            'exception' => new RuntimeException('Mail failed.'),
        ]),
    );

    $activities = Activity::query()
        ->whereIn('event', [
            AuditEvent::ClientPortalInviteSent->value,
            AuditEvent::ClientPortalInviteFailed->value,
        ])
        ->get();

    expect($activities)->toHaveCount(2)
        ->and($activities->pluck('event'))->toContain(
            AuditEvent::ClientPortalInviteSent->value,
            AuditEvent::ClientPortalInviteFailed->value,
        )
        ->and((string) json_encode($activities->pluck('properties')->all()))
        ->not->toContain($context['plainTextToken'])
        ->not->toContain('/portal/');
});

test('delivery audit failures are reported without retrying a delivered invitation', function (): void {
    $context = createPortalDossierWithGrant();
    Exceptions::fake();

    $notification = new ClientPortalInviteNotification(
        grantId: $context['grant']->id,
        dossier: $context['dossier'],
        portalUrl: route('portal.exchange', $context['plainTextToken']),
        expiresAt: $context['grant']->expires_at,
        firmName: $context['tenant']->name,
    );
    $notifiable = (new AnonymousNotifiable)->route('mail', $context['client']->email);

    $event = 'eloquent.creating: '.Activity::class;
    Event::listen($event, static function (Activity $activity): void {
        throw_if($activity->event === AuditEvent::ClientPortalInviteSent->value, RuntimeException::class, 'Simulated delivery audit failure.');
    });

    try {
        resolve(LogClientPortalInviteSent::class)->handle(
            new NotificationSent($notifiable, $notification, 'mail'),
        );
    } finally {
        Event::forget($event);
    }

    Exceptions::assertReported(
        fn (RuntimeException $exception): bool => $exception->getMessage()
            === 'Failed to audit a delivered client portal invitation.',
    );
});

test('grant issuance rolls back when its audit transaction fails', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Draft,
    ]);

    $event = 'eloquent.creating: '.Activity::class;
    Event::listen($event, static function (Activity $activity): void {
        throw_if($activity->event === AuditEvent::ClientPortalInviteQueued->value, RuntimeException::class, 'Simulated audit failure.');
    });

    try {
        $this->actingAs($owner)
            ->post(workspaceRoute('workspaces.dossiers.access-grants.store', $tenant, [
                'dossier' => $dossier,
            ]), [
                'expires_in_days' => 7,
                'send_invite' => true,
            ])
            ->assertServerError();
    } finally {
        Event::forget($event);
    }

    expect(ClientAccessGrant::query()->exists())->toBeFalse()
        ->and($dossier->fresh()->status)->toBe(DossierStatus::Draft)
        ->and(Activity::query()
            ->whereIn('event', [
                AuditEvent::ClientPortalAccessGrantCreated->value,
                AuditEvent::ClientPortalInviteQueued->value,
            ])
            ->exists())->toBeFalse();
});

test('portal locale follows the browser language when no cookie is set', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'));

    $this->withHeaders(['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.8'])
        ->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'nl')
            ->where('translations.Secure client portal', 'Beveiligd klantportaal')
            ->where('translations.Questionnaire', 'Vragenlijst')
            ->tap(function (Assert $page): void {
                /** @var array<string, mixed> $props */
                $props = $page->toArray()['props'];
                /** @var array<string, string> $translations */
                $translations = $props['translations'];

                expect($translations['For :name'])->toBe('Voor :name')
                    ->and($translations['This is a secure upload page for :firm. Do not share this link.'])
                    ->toBe('Dit is een beveiligde uploadpagina voor :firm. Deel deze link niet.');
            }));
});

test('portal locale cookie overrides the browser language', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'));

    $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
        ->withUnencryptedCookie('locale', 'nl')
        ->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'nl')
            ->where('translations.Everything has been submitted', 'Alles is ingediend')
            ->tap(function (Assert $page): void {
                /** @var array<string, mixed> $props */
                $props = $page->toArray()['props'];
                /** @var array<string, string> $translations */
                $translations = $props['translations'];

                expect($translations['Access expires :date'])->toBe('Toegang verloopt :date');
            }));
});

test('portal magic-link resolution is timeboxed so a miss cannot be timed apart from a hit', function (string $case, bool $expectsGrant): void {
    ['plainTextToken' => $plainTextToken, 'grant' => $grant] = createPortalDossierWithGrant();

    if ($case === 'revoked') {
        $grant->forceFill(['revoked_at' => now()])->save();
    }

    $token = match ($case) {
        'valid', 'revoked' => $plainTextToken,
        'unknown' => Str::random(64),
        default => '',
    };

    Sleep::fake();

    $resolved = resolve(ResolveClientAccessGrantFromTokenAction::class)->handle($token);

    $expectsGrant
        ? expect($resolved)->toBeInstanceOf(ClientAccessGrant::class)
        : expect($resolved)->toBeNull();

    // The floor covers every outcome, including the empty-token short circuit
    // that returns before touching the database at all.
    Sleep::assertSleptTimes(1);
})->with([
    'valid' => ['valid', true],
    'unknown token' => ['unknown', false],
    'revoked grant' => ['revoked', false],
    'empty token' => ['empty', false],
]);

test('portal access is recorded after the response with the tenant still resolvable', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']))
        ->assertRedirect(route('portal.show'));

    $this->get(route('portal.show'))->assertOk();

    // The dossier stamp is the fragile half: it runs through TenantScope via
    // toBase(), so a deferred callback that lost the current tenant would
    // silently update zero rows instead of failing loudly.
    expect($context['dossier']->fresh()->last_client_opened_at)->not->toBeNull()
        ->and($context['grant']->fresh()->last_used_at)->not->toBeNull();

    $activity = Activity::query()
        ->where('event', AuditEvent::ClientPortalAccessed->value)
        ->sole();

    expect($activity->tenant_id)->toBe($context['tenant']->id);
});
