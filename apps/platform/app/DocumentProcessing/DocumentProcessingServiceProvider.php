<?php

declare(strict_types=1);

namespace App\DocumentProcessing;

use App\DocumentProcessing\Contracts\Ocr\DocumentOcr;
use App\DocumentProcessing\Contracts\Ocr\DocumentOverviewNormalizer;
use App\DocumentProcessing\Enums\OcrDriver;
use App\DocumentProcessing\Presenters\Ocr\UploadedDocumentPipelineSubjectPresenter;
use App\DocumentProcessing\Runners\Ocr\OcrPipelineRunner;
use App\DocumentProcessing\Services\Ocr\Configuration\OcrConfigurationValidator;
use App\DocumentProcessing\Services\Ocr\Drivers\OcrDriverFactory;
use App\DocumentProcessing\Services\Ocr\Normalization\AgentDocumentOverviewNormalizer;
use App\Pipelines\Support\PipelineRunnerRegistry;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use Aws\Sns\MessageValidator;
use Aws\Sqs\SqsClient;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;

use function is_string;

/**
 * OCR / AWS container bindings and config validation.
 */
final class DocumentProcessingServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(TextractClient::class, fn (): TextractClient => new TextractClient($this->awsClientConfig('ocr.textract.region')));

        $this->app->singleton(SqsClient::class, fn (): SqsClient => new SqsClient($this->awsClientConfig('ocr.textract.region')));

        // Stateless — safe to share across Octane workers / long-running consume loops.
        $this->app->singleton(MessageValidator::class);

        $this->app->bind(DocumentOverviewNormalizer::class, AgentDocumentOverviewNormalizer::class);

        $this->app->bind(
            DocumentOcr::class,
            fn ($app): DocumentOcr => $app->make(OcrDriverFactory::class)->make(
                Config::string('ocr.driver', OcrDriver::Mock->value),
            ),
        );
    }

    public function boot(
        OcrConfigurationValidator $ocrConfigurationValidator,
        PipelineRunnerRegistry $pipelineRunners,
        PipelineSubjectPresenterRegistry $subjectPresenters,
    ): void {
        $ocrConfigurationValidator->validate();

        $pipelineRunners->register(new OcrPipelineRunner());
        $subjectPresenters->register(new UploadedDocumentPipelineSubjectPresenter());
    }

    /**
     * Shared AWS SDK client options. Credentials come from ocr.textract.* (IAM role in prod).
     * When ocr.textract.endpoint is set (local Floci), all OCR AWS clients share that base URL.
     *
     * @return array{
     *     version: string,
     *     region: string,
     *     endpoint?: string,
     *     credentials?: array{key: string, secret: string, token?: string}
     * }
     */
    private function awsClientConfig(string $regionConfigKey): array
    {
        // Credentials / endpoint are optional nulls (IAM role in prod, Floci locally).
        $key = Config::get('ocr.textract.key');
        $secret = Config::get('ocr.textract.secret');
        $token = Config::get('ocr.textract.token');
        $region = Config::string($regionConfigKey, 'eu-west-1');
        $endpoint = Config::get('ocr.textract.endpoint');

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

            // Temporary STS credentials (assumed OCR operator role) include a session token.
            if (is_string($token) && $token !== '') {
                $credentials['token'] = $token;
            }

            $clientConfig['credentials'] = $credentials;
        }

        return $clientConfig;
    }
}
