<?php

declare(strict_types=1);

namespace App\Providers;

use App\Accounts\Models\User;
use App\AudioProcessing\Enums\TranscriptionDriver;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\OcrDriver;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Support\DebugbarStorageOpen;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Telescope\TelescopeServiceProvider;
use Override;
use RuntimeException;

use function in_array;
use function is_string;
use function mb_strtolower;

/**
 * Application composition root for cross-cutting defaults.
 * Domain bindings, policies, and OCR/AWS wiring live in domain *ServiceProvider classes.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->configureDebugbarStorageOpen();

        if ($this->app->environment('local') && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedProxies();
        $this->configureMorphMap();
        $this->configureDefaults();
        $this->configureFactoryDiscovery();
        $this->configureDevCommands();
    }

    /**
     * Apply TRUSTED_PROXIES after config is loaded. trustProxies(at:) cannot
     * take a deferred closure (unlike trustHosts), so bootstrap only sets
     * forwarded headers and this boots the concrete proxy list.
     *
     * Production must use LB/VPC CIDRs or PRIVATE_SUBNETS — never REMOTE_ADDR.
     */
    private function configureTrustedProxies(): void
    {
        // May be null when unset — typed string() rejects null.
        $proxies = Config::get('production.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Reset links and other absolute URLs must ignore the request Host
        // header in production: a spoofed header must never redirect a
        // legitimate user's email link to an attacker-controlled domain.
        // forceRootUrl() alone leaves the scheme derived from the request
        // (UrlGenerator::formatScheme falls back to $request->getScheme()),
        // so it must be paired with forceScheme().
        if (app()->isProduction()) {
            URL::useOrigin(Config::string('app.url'));
            URL::forceScheme('https');
        }

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

        Model::automaticallyEagerLoadRelationships();
    }

    /**
     * Factories live flat under Database\Factories\{Model}Factory while models
     * are grouped by domain (App\Accounts\Models\User, etc.).
     */
    private function configureFactoryDiscovery(): void
    {
        Factory::guessFactoryNamesUsing($this->guessFactoryForModel(...));
    }

    /**
     * @param  class-string<Model>  $modelName
     * @return class-string<Factory<Model>>
     */
    private function guessFactoryForModel(string $modelName): string
    {
        $factory = 'Database\\Factories\\'.class_basename($modelName).'Factory';

        throw_unless(is_a($factory, Factory::class, true), RuntimeException::class, "Factory [{$factory}] does not exist.");

        return $factory;
    }

    /**
     * Keep persisted polymorphic types independent from PHP namespaces.
     * Cross-domain map stays here so aliases remain a single source of truth.
     */
    private function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'activity' => Activity::class,
            'audio_recording' => AudioRecording::class,
            'client' => Client::class,
            'client_access_grant' => ClientAccessGrant::class,
            'data_subject_request' => DataSubjectRequest::class,
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
     * Debugbar's default storage.open=null uses IpUtils::isPrivateIp(clientIp).
     * FrankenPHP/Octane boots workers without a client IP, and Symfony throws
     * ValueError on "". That aborts the worker before frankenphp_handle_request().
     *
     * Cast env strings to bools, and register a class name (not a Closure) so
     * `config:cache` / `optimize` can serialize the config.
     */
    private function configureDebugbarStorageOpen(): void
    {
        // Default is null (and may later be bool/class) — typed string() rejects that.
        $open = Config::get('debugbar.storage.open');

        // .env values are strings; cast so Debugbar treats them as bools.
        if (is_string($open) && in_array(mb_strtolower($open), ['true', 'false', '1', '0'], true)) {
            Config::set('debugbar.storage.open', filter_var($open, FILTER_VALIDATE_BOOLEAN));

            return;
        }

        if ($open !== null) {
            return;
        }

        // Class string stays serializable; Debugbar calls ::resolve($request).
        Config::set('debugbar.storage.open', DebugbarStorageOpen::class);
    }

    /**
     * Include Reverb in `php artisan dev` when broadcasting is enabled.
     */
    private function configureDevCommands(): void
    {
        DevCommands::except('server');
        DevCommands::artisan('queue:listen --tries=1 --timeout=0 --queue=ocr', 'ocr');
        DevCommands::artisan('queue:listen --tries=1 --timeout=0 --queue=transcription', 'transcription');
        DevCommands::artisan('queue:listen --tries=1 --timeout=0 --queue=dossier-export', 'dossier-export');

        // Only when the real drivers are on. Leftover Pulumi queue URLs with
        // OCR_DRIVER=mock + Floci must not start consumers against missing queues.
        if ($this->shouldRunTranscribeConsume()) {
            DevCommands::artisan('transcribe:consume', 'transcribe-consume');
        }

        if ($this->shouldRunTextractConsume()) {
            DevCommands::artisan('textract:consume', 'textract-consume');
        }

        // phpunit sets BROADCAST_CONNECTION=null → env converts to PHP null.
        if (Config::get('broadcasting.default') !== 'reverb') {
            return;
        }

        DevCommands::artisan('reverb:start', 'reverb');
    }

    /**
     * Amazon Transcribe completion listener for `php artisan dev`.
     */
    private function shouldRunTranscribeConsume(): bool
    {
        if (Config::string('transcription.driver') !== TranscriptionDriver::Transcribe->value) {
            return false;
        }

        // Queue URL is optional/null while drivers stay on mock.
        $queueUrl = Config::get('transcription.transcribe.results_queue_url');

        return is_string($queueUrl) && $queueUrl !== '';
    }

    /**
     * Textract SNS→SQS completion listener for `php artisan dev`.
     */
    private function shouldRunTextractConsume(): bool
    {
        if (Config::string('ocr.driver') !== OcrDriver::Textract->value) {
            return false;
        }

        // Queue URL is optional/null while drivers stay on mock.
        $queueUrl = Config::get('ocr.textract.results_queue_url');

        return is_string($queueUrl) && $queueUrl !== '';
    }
}
