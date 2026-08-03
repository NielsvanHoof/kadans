<?php

declare(strict_types=1);

use App\AudioProcessing\Contracts\StartsAudioTranscription;
use App\AudioProcessing\Services\Drivers\AmazonTranscribeAudioTranscriber;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use Aws\Result;
use Aws\TranscribeService\TranscribeServiceClient;
use Mockery;

test('amazon transcribe driver starts a job and stores the job name', function (): void {
    config([
        'transcription.driver' => 'transcribe',
        'transcription.transcribe.bucket' => 'qompose-docs',
        'transcription.transcribe.language_code' => 'nl-NL',
        'transcription.transcribe.max_speaker_labels' => 4,
        'transcription.transcribe.output_prefix' => 'transcriptions',
    ]);

    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'path' => 'tenants/1/dossiers/1/audio/call.mp3',
        'transcription_status' => TranscriptionStatus::Processing,
        'transcription_started_at' => now(),
    ]);

    $clientMock = Mockery::mock(TranscribeServiceClient::class);
    $clientMock->shouldReceive('startTranscriptionJob')
        ->once()
        ->with(Mockery::on(fn(array $args): bool => ($args['LanguageCode'] ?? null) === 'nl-NL'
            && ($args['Media']['MediaFileUri'] ?? null) === 's3://qompose-docs/'.$recording->path
            && ($args['Settings']['ShowSpeakerLabels'] ?? null) === true
            && ! array_key_exists('NotificationChannel', $args)
            && ! array_key_exists('JobExecutionSettings', $args)))
        ->andReturn(new Result([]));

    $this->app->instance(TranscribeServiceClient::class, $clientMock);

    resolve(AmazonTranscribeAudioTranscriber::class)->start($recording);

    $recording->refresh();

    expect($recording->transcribe_job_id)->toStartWith('audio-'.$recording->id.'-')
        ->and($recording->transcription_status)->toBe(TranscriptionStatus::Processing)
        ->and(resolve(StartsAudioTranscription::class))->toBeInstanceOf(AmazonTranscribeAudioTranscriber::class);
});
