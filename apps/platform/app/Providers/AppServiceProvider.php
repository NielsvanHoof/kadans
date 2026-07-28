<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Ocr\DescribesDocumentOverview;
use App\Contracts\Ocr\StartsDocumentOcr;
use App\Contracts\Production\ChecksReadiness;
use App\Enums\OcrDriver;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientAccessGrant;
use App\Models\DocumentRequest;
use App\Models\Dossier;
use App\Models\QuestionnaireTemplate;
use App\Models\Pipeline;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Policies\Audit\ActivityPolicy;
use App\Policies\Clients\ClientPolicy;
use App\Policies\Dossiers\DocumentRequestPolicy;
use App\Policies\Dossiers\DossierPolicy;
use App\Policies\Dossiers\UploadedDocumentPolicy;
use App\Policies\Portal\ClientAccessGrantPolicy;
use App\Policies\Questionnaires\QuestionnaireTemplatePolicy;
use App\Policies\Reporting\PipelinePolicy;
use App\Policies\Tenancy\TenantInvitationPolicy;
use App\Policies\Tenancy\TenantMembershipPolicy;
use App\Services\Ocr\Configuration\OcrConfigurationValidator;
use App\Services\Ocr\Drivers\OcrDriverFactory;
use App\Services\Ocr\Normalization\BedrockDocumentOverviewNormalizer;
use App\Services\Production\InfrastructureReadinessCheck;
use App\Services\Production\ProductionConfigurationValidator;
use App\Support\Platform\SupportSession;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sqs\SqsClient;
use Aws\Textract\TextractClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

use function in_array;
use function is_string;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerOcrBindings();
        $this->app->bind(ChecksReadiness::class, InfrastructureReadinessCheck::class);

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(
        OcrConfigurationValidator $ocrConfigurationValidator,
        ProductionConfigurationValidator $productionConfigurationValidator,
    ): void {
        $ocrConfigurationValidator->validate();
        $productionConfigurationValidator->validate();
        $this->configurePolicies();
        $this->configureSupportPermissionGate();
        $this->configureDefaults();
        $this->configureDevCommands();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        Model::shouldBeStrict();

        Model::preventLazyLoading(! app()->isProduction());
    }

    /**
     * Grant read-only abilities to platform support users in an active firm session.
     * Hard-denies mutate abilities while a support session is active.
     * Only intercepts Spatie permission strings — policy methods (viewAny, etc.) continue normally.
     */
    private function configureSupportPermissionGate(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User || ! $user->isPlatformSupport()) {
                return null;
            }

            // Policy abilities like "viewAny" must not be short-circuited here.
            if (! in_array($ability, Permission::values(), true)) {
                return null;
            }

            $tenant = Tenant::current();

            if (! $tenant instanceof Tenant) {
                return null;
            }

            $supportSession = app(SupportSession::class);

            // Support session takes priority over firm membership (read-only overlay).
            if (! $supportSession->isActiveFor($tenant)) {
                return null;
            }

            return $supportSession->allowsPermission($ability);
        });
    }

    /**
     * Include Reverb in `php artisan dev` when broadcasting is enabled.
     */
    private function configureDevCommands(): void
    {
        DevCommands::except('server');

        if (config('broadcasting.default') !== 'reverb') {
            return;
        }

        DevCommands::artisan('reverb:start', 'reverb');
    }

    /**
     * Bind OCR driver + AWS clients used by Textract start/consume and Bedrock structuring.
     */
    private function registerOcrBindings(): void
    {
        $this->app->singleton(TextractClient::class, function (): TextractClient {
            return new TextractClient($this->awsClientConfig('ocr.textract.region'));
        });

        $this->app->singleton(SqsClient::class, function (): SqsClient {
            return new SqsClient($this->awsClientConfig('ocr.textract.region'));
        });

        // Bedrock may use a different region than Textract (inference profiles).
        $this->app->singleton(BedrockRuntimeClient::class, function (): BedrockRuntimeClient {
            return new BedrockRuntimeClient($this->awsClientConfig('ocr.bedrock.region'));
        });

        $this->app->bind(DescribesDocumentOverview::class, BedrockDocumentOverviewNormalizer::class);

        $this->app->bind(
            StartsDocumentOcr::class,
            fn ($app): StartsDocumentOcr => $app->make(OcrDriverFactory::class)->make(
                (string) config('ocr.driver', OcrDriver::Mock->value),
            ),
        );
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

    private function configurePolicies(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(ClientAccessGrant::class, ClientAccessGrantPolicy::class);
        Gate::policy(DocumentRequest::class, DocumentRequestPolicy::class);
        Gate::policy(Dossier::class, DossierPolicy::class);
        Gate::policy(QuestionnaireTemplate::class, QuestionnaireTemplatePolicy::class);
        Gate::policy(Pipeline::class, PipelinePolicy::class);
        Gate::policy(TenantInvitation::class, TenantInvitationPolicy::class);
        Gate::policy(TenantMembership::class, TenantMembershipPolicy::class);
        Gate::policy(UploadedDocument::class, UploadedDocumentPolicy::class);
    }
}
