<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\AudioProcessing\Actions\CompleteTranscribeJobAction;
use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use Aws\Result;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

covers(CompleteTranscribeJobAction::class);

test('complete action persists transcript segments and notifies the uploader', function (): void {
    Notification::fake();
    Storage::fake('s3');

    $transcriptKey = 'transcriptions/1/99/audio-99-deadbeef.json';
    Storage::disk('s3')->put($transcriptKey, json_encode([
        'results' => [
            'transcripts' => [['transcript' => 'Hello world.']],
            'speaker_labels' => [
                'segments' => [
                    [
                        'start_time' => '0.0',
                        'end_time' => '1.0',
                        'speaker_label' => 'spk_0',
                        'items' => [['item_id' => '0']],
                    ],
                ],
            ],
            'items' => [
                [
                    'id' => '0',
                    'type' => 'pronunciation',
                    'alternatives' => [['content' => 'Hello']],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config([
        'transcription.transcribe.bucket' => 'qompose-docs',
    ]);

    ['tenant' => $tenant] = provisionWorkspace();
    $uploader = User::factory()->create();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $jobId = 'audio-99-deadbeef';
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'uploaded_by' => $uploader->id,
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => $jobId,
        'transcription_started_at' => now(),
    ]);

    $clientMock = Mockery::mock(TranscribeServiceClient::class);
    $clientMock->shouldReceive('getTranscriptionJob')
        ->once()
        ->with(['TranscriptionJobName' => $jobId])
        ->andReturn(new Result([
            'TranscriptionJob' => [
                'Transcript' => [
                    'TranscriptFileUri' => "https://s3.eu-west-1.amazonaws.com/qompose-docs/{$transcriptKey}",
                ],
            ],
        ]));

    $this->app->instance(TranscribeServiceClient::class, $clientMock);

    $applied = resolve(CompleteTranscribeJobAction::class)->handle([
        'TranscriptionJobName' => $jobId,
        'TranscriptionJobStatus' => 'COMPLETED',
    ]);

    $recording->refresh();

    expect($applied)->toBeTrue()
        ->and($recording->transcription_status)->toBe(TranscriptionStatus::Completed)
        ->and($recording->transcript)->toBe('Hello world.')
        ->and($recording->segments)->not->toBeEmpty()
        ->and($recording->transcription_finished_at)->not->toBeNull();

    Notification::assertSentTo($uploader, AudioTranscriptionCompletedNotification::class);

    $activity = Activity::query()
        ->where('event', AuditEvent::AudioTranscriptionCompleted->value)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->tenant_id)->toBe($tenant->id)
        ->and($activity->subject_id)->toBe($recording->id);
});
