<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use Inertia\Testing\AssertableInertia as Assert;

test('staff can open the transcript page for an audio recording', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Client intake',
    ]);
    $segments = [
        ['text' => 'Hello,', 'speaker' => 'Speaker 1', 'start_seconds' => 0.0, 'end_seconds' => 0.6],
        ['text' => 'this is a test call.', 'speaker' => 'Speaker 2', 'start_seconds' => 0.8, 'end_seconds' => 2.1],
    ];
    $audioRecording = AudioRecording::factory()->transcribed('Hello, this is a test call.', $segments)->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'original_filename' => 'call.mp3',
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
            'audioRecording' => $audioRecording,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('audio-recordings/show')
            ->where('audio_recording.original_filename', 'call.mp3')
            ->where('audio_recording.transcription_status', TranscriptionStatus::Completed->value)
            ->where('audio_recording.transcript', 'Hello, this is a test call.')
            // JSON round-trips whole-number floats as ints — compare against the round-tripped shape.
            ->where('audio_recording.segments', json_decode(json_encode($segments), true))
            ->where('dossier.id', $dossier->id)
            ->where('dossier.title', 'Client intake')
        );
});

test('staff from another tenant cannot open a transcript page', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Firm A');
    ['owner' => $ownerB, 'tenant' => $tenantB] = provisionWorkspace('Firm B');

    actingInWorkspace($tenantA);
    $client = Client::factory()->create(['tenant_id' => $tenantA->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenantA->id,
        'client_id' => $client->id,
    ]);
    $audioRecording = AudioRecording::factory()->transcribed()->create([
        'tenant_id' => $tenantA->id,
        'dossier_id' => $dossier->id,
    ]);

    $this->actingAs($ownerB)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.audio-recordings.show', $tenantA, [
            'audioRecording' => $audioRecording,
        ]))
        ->assertNotFound();
});

test('staff must recently confirm their password before opening a transcript page', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $audioRecording = AudioRecording::factory()->transcribed()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
            'audioRecording' => $audioRecording,
        ]))
        ->assertRedirect(route('password.confirm'));
});

test('guest cannot open the transcript page', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $audioRecording = AudioRecording::factory()->transcribed()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $this->get(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
        'audioRecording' => $audioRecording,
    ]))->assertRedirect();
});

test('transcript page island returns json for the audio recording', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $audioRecording = AudioRecording::factory()->transcribed('Transcript body.')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->getJson(workspaceRoute('workspaces.audio-recordings.show', $tenant, [
            'audioRecording' => $audioRecording,
        ]))
        ->assertOk()
        ->assertJsonPath('audio_recording.transcription_status', TranscriptionStatus::Completed->value)
        ->assertJsonPath('audio_recording.transcript', 'Transcript body.')
        ->assertJsonMissingPath('dossier');
});

test('live audio recording island returns processing status without password confirm', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $audioRecording = AudioRecording::factory()->processing()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'original_filename' => 'voicemail.mp3',
    ]);

    $this->actingAs($owner)
        ->getJson(workspaceRoute('workspaces.audio-recordings.live', $tenant, [
            'audioRecording' => $audioRecording,
        ]))
        ->assertOk()
        ->assertJsonPath('audio_recording.id', $audioRecording->id)
        ->assertJsonPath('audio_recording.transcription_status', TranscriptionStatus::Processing->value)
        ->assertJsonPath('audio_recording.original_filename', 'voicemail.mp3');
});
