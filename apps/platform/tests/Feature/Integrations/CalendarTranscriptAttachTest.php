<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\AudioRecordingCalendarLink;
use App\Dossiers\Models\Dossier;
use App\Integrations\Actions\RefreshOAuthAccessTokenAction;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Http\Connectors\Google\Requests\Calendar\ListPrimaryEventsRequest;
use App\Integrations\Http\Connectors\Google\Requests\Calendar\PatchEventAttachmentsRequest;
use App\Integrations\Http\Connectors\Google\Requests\Drive\DeleteDriveFileRequest;
use App\Integrations\Models\OAuthConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function enableGoogleWorkspaceOAuth(): void
{
    Config::set('services.google.client_id', 'test-google-client-id');
    Config::set('services.google.client_secret', 'test-google-client-secret');
    Config::set('services.google.redirect', 'http://localhost/auth/google/callback');
}

/**
 * @return list<string>
 */
function googleWorkspaceScopes(): array
{
    return OAuthProvider::Google->scopes();
}

test('calendar suggestions rank client email matches first', function (): void {
    enableGoogleWorkspaceOAuth();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'client@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $recording = AudioRecording::factory()->transcribed('Hello from the call.')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_at' => Date::parse('2026-08-15 12:00:00'),
    ]);

    OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $owner->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => Date::now()->addHour(),
        'scopes' => googleWorkspaceScopes(),
    ]);

    MockClient::global([
        MockResponse::make([
            'items' => [
                [
                    'id' => 'evt-other',
                    'summary' => 'Internal sync',
                    'htmlLink' => 'https://calendar.google.com/event?eid=other',
                    'start' => ['dateTime' => '2026-08-15T10:00:00+02:00'],
                    'end' => ['dateTime' => '2026-08-15T11:00:00+02:00'],
                    'attendees' => [
                        ['email' => 'colleague@example.com'],
                    ],
                ],
                [
                    'id' => 'evt-client',
                    'summary' => 'Client meeting',
                    'htmlLink' => 'https://calendar.google.com/event?eid=client',
                    'start' => ['dateTime' => '2026-08-16T14:00:00+02:00'],
                    'end' => ['dateTime' => '2026-08-16T15:00:00+02:00'],
                    'attendees' => [
                        ['email' => 'Client@example.com'],
                    ],
                ],
            ],
        ]),
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->getJson(workspaceRoute('workspaces.audio-recordings.calendar-suggestions', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->assertOk()
        ->assertJsonPath('events.0.id', 'evt-client')
        ->assertJsonPath('events.0.matches_client_email', true)
        ->assertJsonPath('events.0.provider', 'google')
        ->assertJsonPath('events.1.id', 'evt-other');

    // Google rejects Guzzle's "1" for booleans — must send the literal "true".
    MockClient::global()->assertSent(
        ListPrimaryEventsRequest::class,
    );
});

test('attach transcript uploads to drive and patches calendar event', function (): void {
    enableGoogleWorkspaceOAuth();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id]);
    $recording = AudioRecording::factory()->transcribed('Full transcript text.')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'original_filename' => 'intake.mp3',
    ]);

    OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $owner->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => Date::now()->addHour(),
        'scopes' => googleWorkspaceScopes(),
    ]);

    MockClient::global([
        MockResponse::make(['id' => 'drive-file-1']),
        MockResponse::make([
            'id' => 'drive-file-1',
            'name' => 'intake-transcript.txt',
            'mimeType' => 'text/plain',
            'webViewLink' => 'https://drive.google.com/file/d/drive-file-1/view',
        ]),
        MockResponse::make([
            'id' => 'evt-1',
            'htmlLink' => 'https://calendar.google.com/event?eid=1',
            'attachments' => [],
        ]),
        MockResponse::make([
            'id' => 'evt-1',
            'htmlLink' => 'https://calendar.google.com/event?eid=1',
        ]),
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->from(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->post(workspaceRoute('workspaces.audio-recordings.calendar-events.attach', $tenant, [
            'audioRecording' => $recording,
        ]), [
            'event_id' => 'evt-1',
            'provider' => 'google',
        ])
        ->assertRedirect();

    $recording->refresh();

    $link = $recording->calendarLinks()->first();

    expect($link)->not->toBeNull()
        ->and($link->provider)->toBe(OAuthProvider::Google)
        ->and($link->external_event_id)->toBe('evt-1')
        ->and($link->external_file_id)->toBe('drive-file-1')
        ->and($link->external_event_url)->toContain('calendar.google.com')
        ->and($link->linked_at)->not->toBeNull()
        ->and($link->linked_by_user_id)->toBe($owner->id);

    MockClient::global()->assertSent(
        PatchEventAttachmentsRequest::class,
    );
});

test('attach rejects unsupported calendar providers', function (): void {
    enableGoogleWorkspaceOAuth();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id]);
    $recording = AudioRecording::factory()->transcribed('Ready')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $owner->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => Date::now()->addHour(),
        'scopes' => googleWorkspaceScopes(),
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->from(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->post(workspaceRoute('workspaces.audio-recordings.calendar-events.attach', $tenant, [
            'audioRecording' => $recording,
        ]), [
            'event_id' => 'evt-1',
            'provider' => OAuthProvider::Microsoft->value,
        ])
        ->assertRedirect();

    expect($recording->calendarLinks()->count())->toBe(0);
});

test('suggestions require google workspace scopes', function (): void {
    enableGoogleWorkspaceOAuth();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id]);
    $recording = AudioRecording::factory()->transcribed('Ready')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $owner->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => Date::now()->addHour(),
        'scopes' => ['openid', 'profile', 'email'],
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->getJson(workspaceRoute('workspaces.audio-recordings.calendar-suggestions', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->assertConflict()
        ->assertJsonPath('needs_reconsent', true);
});

test('refresh oauth access token updates google connection', function (): void {
    enableGoogleWorkspaceOAuth();

    $user = User::factory()->create();
    $connection = OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $user->id,
        'access_token' => 'old-access',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => Date::now()->subMinute(),
        'scopes' => googleWorkspaceScopes(),
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new-access',
            'expires_in' => 3600,
        ]),
    ]);

    $refreshed = resolve(RefreshOAuthAccessTokenAction::class)->handle($connection);

    expect($refreshed->access_token)->toBe('new-access')
        ->and($refreshed->refresh_token)->toBe('refresh-token');
});

test('unlink transcript patches calendar event and deletes drive file', function (): void {
    enableGoogleWorkspaceOAuth();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id]);
    $recording = AudioRecording::factory()->transcribed('Ready')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $link = AudioRecordingCalendarLink::factory()->create([
        'tenant_id' => $tenant->id,
        'audio_recording_id' => $recording->id,
        'external_event_id' => 'evt-1',
        'external_file_id' => 'drive-file-1',
        'linked_by_user_id' => $owner->id,
    ]);

    OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $owner->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => Date::now()->addHour(),
        'scopes' => googleWorkspaceScopes(),
    ]);

    MockClient::global([
        MockResponse::make([
            'id' => 'evt-1',
            'htmlLink' => 'https://calendar.google.com/event?eid=1',
            'attachments' => [
                [
                    'fileUrl' => 'https://drive.google.com/file/d/drive-file-1/view',
                    'mimeType' => 'text/plain',
                    'title' => 'intake-transcript.txt',
                    'fileId' => 'drive-file-1',
                ],
                [
                    'fileUrl' => 'https://drive.google.com/file/d/other/view',
                    'mimeType' => 'application/pdf',
                    'title' => 'agenda.pdf',
                    'fileId' => 'other',
                ],
            ],
        ]),
        MockResponse::make([
            'id' => 'evt-1',
            'htmlLink' => 'https://calendar.google.com/event?eid=1',
        ]),
        MockResponse::make(body: '', status: 204),
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->from(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->delete(workspaceRoute('workspaces.audio-recordings.calendar-links.destroy', $tenant, [
            'audioRecording' => $recording,
            'calendarLink' => $link,
        ]))
        ->assertRedirect();

    expect($recording->calendarLinks()->count())->toBe(0);

    MockClient::global()->assertSent(PatchEventAttachmentsRequest::class);
    MockClient::global()->assertSent(DeleteDriveFileRequest::class);
});

test('unlink treats a missing calendar event as already unlinked', function (): void {
    enableGoogleWorkspaceOAuth();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id]);
    $recording = AudioRecording::factory()->transcribed('Ready')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $link = AudioRecordingCalendarLink::factory()->create([
        'tenant_id' => $tenant->id,
        'audio_recording_id' => $recording->id,
        'external_event_id' => 'evt-gone',
        'external_file_id' => 'drive-file-1',
        'linked_by_user_id' => $owner->id,
    ]);

    OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $owner->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => Date::now()->addHour(),
        'scopes' => googleWorkspaceScopes(),
    ]);

    MockClient::global([
        MockResponse::make(['error' => ['message' => 'Not Found']], 404),
        MockResponse::make(body: '', status: 204),
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->from(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->delete(workspaceRoute('workspaces.audio-recordings.calendar-links.destroy', $tenant, [
            'audioRecording' => $recording,
            'calendarLink' => $link,
        ]))
        ->assertRedirect();

    expect($recording->calendarLinks()->count())->toBe(0);

    MockClient::global()->assertSent(DeleteDriveFileRequest::class);
});

test('unlink treats a missing drive file as already unlinked', function (): void {
    enableGoogleWorkspaceOAuth();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id]);
    $recording = AudioRecording::factory()->transcribed('Ready')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $link = AudioRecordingCalendarLink::factory()->create([
        'tenant_id' => $tenant->id,
        'audio_recording_id' => $recording->id,
        'external_event_id' => 'evt-1',
        'external_file_id' => 'drive-file-1',
        'linked_by_user_id' => $owner->id,
    ]);

    OAuthConnection::factory()->forProvider(OAuthProvider::Google)->create([
        'user_id' => $owner->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => Date::now()->addHour(),
        'scopes' => googleWorkspaceScopes(),
    ]);

    MockClient::global([
        MockResponse::make([
            'id' => 'evt-1',
            'htmlLink' => 'https://calendar.google.com/event?eid=1',
            'attachments' => [
                [
                    'fileUrl' => 'https://drive.google.com/file/d/drive-file-1/view',
                    'mimeType' => 'text/plain',
                    'title' => 'intake-transcript.txt',
                    'fileId' => 'drive-file-1',
                ],
            ],
        ]),
        MockResponse::make([
            'id' => 'evt-1',
            'htmlLink' => 'https://calendar.google.com/event?eid=1',
        ]),
        MockResponse::make(['error' => ['message' => 'Not Found']], 404),
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->from(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->delete(workspaceRoute('workspaces.audio-recordings.calendar-links.destroy', $tenant, [
            'audioRecording' => $recording,
            'calendarLink' => $link,
        ]))
        ->assertRedirect();

    expect($recording->calendarLinks()->count())->toBe(0);
});

test('unlink cannot target a calendar link on another recording', function (): void {
    enableGoogleWorkspaceOAuth();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id]);
    $recording = AudioRecording::factory()->transcribed('Ready')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $otherRecording = AudioRecording::factory()->transcribed('Other')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $link = AudioRecordingCalendarLink::factory()->create([
        'tenant_id' => $tenant->id,
        'audio_recording_id' => $otherRecording->id,
        'external_event_id' => 'evt-other',
        'external_file_id' => 'drive-other',
        'linked_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()])
        ->delete(workspaceRoute('workspaces.audio-recordings.calendar-links.destroy', $tenant, [
            'audioRecording' => $recording,
            'calendarLink' => $link,
        ]))
        ->assertNotFound();

    expect($otherRecording->calendarLinks()->count())->toBe(1);
});
