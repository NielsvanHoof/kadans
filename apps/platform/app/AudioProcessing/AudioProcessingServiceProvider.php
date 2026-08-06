<?php

declare(strict_types=1);

namespace App\AudioProcessing;

use App\AudioProcessing\Contracts\StartsAudioTranscription;
use App\AudioProcessing\Enums\TranscriptionDriver;
use App\AudioProcessing\Retry\AudioTranscriptionPipelineRetryRunner;
use App\AudioProcessing\Services\Configuration\TranscriptionConfigurationValidator;
use App\AudioProcessing\Services\TranscriptionDriverFactory;
use App\Pipelines\Support\PipelineRetryRunnerRegistry;
use Aws\TranscribeService\TranscribeServiceClient;
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
            StartsAudioTranscription::class,
            fn ($app): StartsAudioTranscription => $app->make(TranscriptionDriverFactory::class)->make(
                (string) config('transcription.driver', TranscriptionDriver::Mock->value),
            ),
        );
    }

    public function boot(
        TranscriptionConfigurationValidator $transcriptionConfigurationValidator,
        PipelineRetryRunnerRegistry $retryRunners,
    ): void {
        $transcriptionConfigurationValidator->validate();

        $retryRunners->register(new AudioTranscriptionPipelineRetryRunner());
    }

    /**
     * Shared AWS SDK client options. Credentials come from transcription.transcribe.*
     * (same AWS_* env as OCR; IAM role in prod).
     *
     * @return array{
     *     version: string,
     *     region: string,
     *     credentials?: array{key: string, secret: string, token?: string}
     * }
     */
    private function awsClientConfig(): array
    {
        $key = config('transcription.transcribe.key');
        $secret = config('transcription.transcribe.secret');
        $token = config('transcription.transcribe.token');
        $region = config('transcription.transcribe.region', 'eu-west-1');

        /** @var array{version: string, region: string, credentials?: array{key: string, secret: string, token?: string}} $clientConfig */
        $clientConfig = [
            'version' => 'latest',
            'region' => is_string($region) && $region !== '' ? $region : 'eu-west-1',
        ];

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
