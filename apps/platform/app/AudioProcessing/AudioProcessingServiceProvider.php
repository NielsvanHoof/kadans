<?php

declare(strict_types=1);

namespace App\AudioProcessing;

use App\AudioProcessing\Contracts\AudioTranscriber;
use App\AudioProcessing\Enums\TranscriptionDriver;
use App\AudioProcessing\Presenters\AudioRecordingPipelineSubjectPresenter;
use App\AudioProcessing\Runners\AudioTranscriptionPipelineRunner;
use App\AudioProcessing\Services\Configuration\TranscriptionConfigurationValidator;
use App\AudioProcessing\Services\TranscriptionDriverFactory;
use App\Pipelines\Support\PipelineRunnerRegistry;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;

use function is_string;

/**
 * Transcription container bindings and config validation.
 */
final class AudioProcessingServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            TranscribeServiceClient::class,
            fn (): TranscribeServiceClient => new TranscribeServiceClient($this->awsClientConfig()),
        );

        $this->app->bind(
            AudioTranscriber::class,
            fn ($app): AudioTranscriber => $app->make(TranscriptionDriverFactory::class)->make(
                Config::string('transcription.driver', TranscriptionDriver::Mock->value),
            ),
        );
    }

    public function boot(
        TranscriptionConfigurationValidator $transcriptionConfigurationValidator,
        PipelineRunnerRegistry $pipelineRunners,
        PipelineSubjectPresenterRegistry $subjectPresenters,
    ): void {
        $transcriptionConfigurationValidator->validate();

        $pipelineRunners->register(new AudioTranscriptionPipelineRunner());
        $subjectPresenters->register(new AudioRecordingPipelineSubjectPresenter());
    }

    /**
     * Shared AWS SDK client options. Credentials come from transcription.transcribe.*
     * (same AWS_* env as OCR; IAM role in prod).
     * When transcription.transcribe.endpoint is set (local Floci), the Transcribe client uses it.
     *
     * @return array{
     *     version: string,
     *     region: string,
     *     endpoint?: string,
     *     credentials?: array{key: string, secret: string, token?: string}
     * }
     */
    private function awsClientConfig(): array
    {
        // Credentials / endpoint are optional nulls (IAM role in prod, Floci locally).
        $key = Config::get('transcription.transcribe.key');
        $secret = Config::get('transcription.transcribe.secret');
        $token = Config::get('transcription.transcribe.token');
        $region = Config::string('transcription.transcribe.region', 'eu-west-1');
        $endpoint = Config::get('transcription.transcribe.endpoint');

        /** @var array{version: string, region: string, endpoint?: string, credentials?: array{key: string, secret: string, token?: string}} $clientConfig */
        $clientConfig = [
            'version' => 'latest',
            'region' => $region !== '' ? $region : 'eu-west-1',
        ];

        // Empty in production so the SDK talks to real AWS regional endpoints.
        if (is_string($endpoint) && $endpoint !== '') {
            $clientConfig['endpoint'] = $endpoint;
        }

        if (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
            $credentials = [
                'key' => $key,
                'secret' => $secret,
            ];

            if (is_string($token) && $token !== '') {
                $credentials['token'] = $token;
            }

            $clientConfig['credentials'] = $credentials;
        }

        return $clientConfig;
    }
}
