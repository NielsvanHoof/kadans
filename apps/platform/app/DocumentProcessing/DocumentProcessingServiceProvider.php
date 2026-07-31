<?php

declare(strict_types=1);

namespace App\DocumentProcessing;

use App\DocumentProcessing\Contracts\Ocr\DescribesDocumentOverview;
use App\DocumentProcessing\Contracts\Ocr\StartsDocumentOcr;
use App\DocumentProcessing\Enums\OcrDriver;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Policies\PipelinePolicy;
use App\DocumentProcessing\Services\Ocr\Configuration\OcrConfigurationValidator;
use App\DocumentProcessing\Services\Ocr\Drivers\OcrDriverFactory;
use App\DocumentProcessing\Services\Ocr\Normalization\BedrockDocumentOverviewNormalizer;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sqs\SqsClient;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

use function is_string;

/**
 * OCR / AWS container bindings, config validation, and pipeline authorization.
 */
final class DocumentProcessingServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(TextractClient::class, fn (): TextractClient => new TextractClient($this->awsClientConfig('ocr.textract.region')));

        $this->app->singleton(SqsClient::class, fn (): SqsClient => new SqsClient($this->awsClientConfig('ocr.textract.region')));

        // Bedrock may use a different region than Textract (inference profiles).
        $this->app->singleton(BedrockRuntimeClient::class, fn (): BedrockRuntimeClient => new BedrockRuntimeClient($this->awsClientConfig('ocr.bedrock.region')));

        $this->app->bind(DescribesDocumentOverview::class, BedrockDocumentOverviewNormalizer::class);

        $this->app->bind(
            StartsDocumentOcr::class,
            fn ($app): StartsDocumentOcr => $app->make(OcrDriverFactory::class)->make(
                (string) config('ocr.driver', OcrDriver::Mock->value),
            ),
        );
    }

    public function boot(OcrConfigurationValidator $ocrConfigurationValidator): void
    {
        $ocrConfigurationValidator->validate();

        Gate::policy(Pipeline::class, PipelinePolicy::class);
    }

    /**
     * Shared AWS SDK client options. Credentials come from ocr.textract.* (IAM role in prod).
     *
     * @return array{
     *     version: string,
     *     region: string,
     *     credentials?: array{key: string, secret: string, token?: string}
     * }
     */
    private function awsClientConfig(string $regionConfigKey): array
    {
        $key = config('ocr.textract.key');
        $secret = config('ocr.textract.secret');
        $token = config('ocr.textract.token');
        $region = config($regionConfigKey, 'eu-west-1');

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

            // Temporary STS credentials (assumed OCR operator role) include a session token.
            if (is_string($token) && $token !== '') {
                $credentials['token'] = $token;
            }

            $clientConfig['credentials'] = $credentials;
        }

        return $clientConfig;
    }
}
