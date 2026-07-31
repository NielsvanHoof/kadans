<?php

declare(strict_types=1);

namespace App\Providers;

use App\Accounts\Models\User;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
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
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Telescope\TelescopeServiceProvider;
use Override;

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
        $this->configureMorphMap();
        $this->configureDefaults();
        $this->configureFactoryDiscovery();
        $this->configureDevCommands();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
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
     * Factories live flat under Database\Factories\{Model}Factory while models
     * are grouped by domain (App\Accounts\Models\User, etc.).
     */
    private function configureFactoryDiscovery(): void
    {
        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory',
        );
    }

    /**
     * Keep persisted polymorphic types independent from PHP namespaces.
     * Cross-domain map stays here so aliases remain a single source of truth.
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
     * Debugbar's default storage.open=null uses IpUtils::isPrivateIp(clientIp).
     * FrankenPHP/Octane boots workers without a client IP, and Symfony throws
     * ValueError on "". That aborts the worker before frankenphp_handle_request().
     *
     * Cast env strings to bools, and register a class name (not a Closure) so
     * `config:cache` / `optimize` can serialize the config.
     */
    private function configureDebugbarStorageOpen(): void
    {
        $open = config('debugbar.storage.open');

        // .env values are strings; cast so Debugbar treats them as bools.
        if (is_string($open) && in_array(mb_strtolower($open), ['true', 'false', '1', '0'], true)) {
            config([
                'debugbar.storage.open' => filter_var($open, FILTER_VALIDATE_BOOLEAN),
            ]);

            return;
        }

        if ($open !== null) {
            return;
        }

        // Class string stays serializable; Debugbar calls ::resolve($request).
        config([
            'debugbar.storage.open' => DebugbarStorageOpen::class,
        ]);
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
}
