<?php

declare(strict_types=1);

namespace App\Providers;

use App\Accounts\Models\User;
use App\Audit\Models\Activity;
use App\Audit\Policies\ActivityPolicy;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Policies\ClientAccessGrantPolicy;
use App\Clients\Models\Client;
use App\Clients\Policies\ClientPolicy;
use App\DocumentProcessing\Contracts\Ocr\DescribesDocumentOverview;
use App\DocumentProcessing\Contracts\Ocr\StartsDocumentOcr;
use App\DocumentProcessing\Enums\OcrDriver;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;
use App\DocumentProcessing\Policies\PipelinePolicy;
use App\DocumentProcessing\Services\Ocr\Configuration\OcrConfigurationValidator;
use App\DocumentProcessing\Services\Ocr\Drivers\OcrDriverFactory;
use App\DocumentProcessing\Services\Ocr\Normalization\BedrockDocumentOverviewNormalizer;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Policies\DocumentRequestPolicy;
use App\Dossiers\Policies\DossierPolicy;
use App\Dossiers\Policies\UploadedDocumentPolicy;
use App\Platform\Console\PruneModelsCommand;
use App\Platform\Contracts\Production\ChecksReadiness;
use App\Platform\Services\Production\InfrastructureReadinessCheck;
use App\Platform\Services\Production\ProductionConfigurationValidator;
use App\Platform\Support\SupportSession;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use App\Questionnaires\Policies\QuestionnaireTemplatePolicy;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Policies\TenantInvitationPolicy;
use App\Tenancy\Policies\TenantMembershipPolicy;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sqs\SqsClient;
use Aws\Textract\TextractClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        $this->commands([
            PruneModelsCommand::class,
        ]);
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
        $this->configureMorphMap();
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
     * Keep persisted polymorphic types independent from PHP namespaces.
     */
    private function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'activity' => Activity::class,
            'client' => Client::class,
            'client_access_grant' => ClientAccessGrant::class,
            'document_request' => DocumentRequest::class,
            'dossier' => Dossier::class,
            'dossier_export' => DossierExport::class,
            'pipeline' => Pipeline::class,
            'pipeline_step' => PipelineStep::class,
            'questionnaire_template' => QuestionnaireTemplate::class,
            'questionnaire_template_item' => QuestionnaireTemplateItem::class,
            'tenant' => Tenant::class,
            'tenant_invitation' => TenantInvitation::class,
            'tenant_membership' => TenantMembership::class,
            'uploaded_document' => UploadedDocument::class,
            'user' => User::class,
        ], merge: false);
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
