<?php

declare(strict_types=1);

use App\AudioProcessing\Contracts\AudioTranscriber;
use App\AudioProcessing\Services\Drivers\AmazonTranscribeAudioTranscriber;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use Aws\Result;
use Aws\TranscribeService\TranscribeServiceClient;

test('amazon transcribe driver starts a job and stores the job name', function (): void {
    config([
        'transcription.driver' => 'transcribe',
        'transcription.transcribe.bucket' => 'qompose-docs',
        'transcription.transcribe.language_options' => ['nl-NL', 'en-US'],
        'transcription.transcribe.max_speaker_labels' => 4,
        'transcription.transcribe.output_prefix' => 'transcriptions',
    ]);

    ['tenant' => $tenant] = provisionWorkspace();
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
        ->with(Mockery::on(fn (array $args): bool => ($args['IdentifyLanguage'] ?? null) === true
            && ($args['LanguageOptions'] ?? null) === ['nl-NL', 'en-US']
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
        ->and(resolve(AudioTranscriber::class))->toBeInstanceOf(AmazonTranscribeAudioTranscriber::class);
});

describe('configured language options', function (): void {
    function transcribableRecording(): AudioRecording
    {
        ['tenant' => $tenant] = provisionWorkspace();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $dossier = Dossier::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);

        return AudioRecording::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'path' => 'tenants/1/dossiers/1/audio/call.mp3',
            'transcription_status' => TranscriptionStatus::Processing,
            'transcription_started_at' => now(),
        ]);
    }

    test('a locale Transcribe does not accept is rejected before the API call', function (mixed $language): void {
        // These used to be filtered to "non-empty string" and sent anyway, so a
        // typo failed at AWS once per recording, asynchronously, after the
        // upload was accepted and the pipeline had started.
        config([
            'transcription.driver' => 'transcribe',
            'transcription.transcribe.bucket' => 'qompose-docs',
            'transcription.transcribe.language_options' => ['nl-NL', $language],
        ]);

        $clientMock = Mockery::mock(TranscribeServiceClient::class);
        $clientMock->shouldNotReceive('startTranscriptionJob');
        $this->app->instance(TranscribeServiceClient::class, $clientMock);

        $recording = transcribableRecording();

        expect(fn (): mixed => resolve(AmazonTranscribeAudioTranscriber::class)->start($recording))
            ->toThrow(InvalidArgumentException::class)
            ->and($recording->refresh()->transcribe_job_id)
            ->toBeNull();
    })->with([
        'a typo' => 'nl-NL1',
        'a bare language' => 'nl',
        'the wrong separator' => 'nl_NL',
        'not a string' => 42,
    ]);

    test('every locale Transcribe accepts stays configurable', function (): void {
        // The allowlist is the SDK's own LanguageCode enum, not a curated
        // product list, so a firm working in another language is not blocked.
        // Compared against the model rather than pinned to a count, so an SDK
        // bump that adds a locale says which one is missing.
        $model = require base_path(
            'vendor/aws/aws-sdk-php/src/data/transcribe/2017-10-26/api-2.json.php',
        );

        $accepted = $model['shapes']['LanguageCode']['enum'];

        sort($accepted);

        expect(AmazonTranscribeAudioTranscriber::SUPPORTED_LANGUAGES)->toBe($accepted);

        config([
            'transcription.driver' => 'transcribe',
            'transcription.transcribe.bucket' => 'qompose-docs',
            'transcription.transcribe.language_options' => ['de-DE', 'fr-FR'],
        ]);

        $clientMock = Mockery::mock(TranscribeServiceClient::class);
        $clientMock->shouldReceive('startTranscriptionJob')
            ->once()
            ->with(Mockery::on(fn (array $args): bool => ($args['LanguageOptions'] ?? null) === ['de-DE', 'fr-FR']))
            ->andReturn(new Result([]));
        $this->app->instance(TranscribeServiceClient::class, $clientMock);

        resolve(AmazonTranscribeAudioTranscriber::class)->start(transcribableRecording());
    });
});
