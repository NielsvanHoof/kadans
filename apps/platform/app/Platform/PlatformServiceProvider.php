<?php

declare(strict_types=1);

namespace App\Platform;

use App\Accounts\Actions\Localization\LoadFrontendTranslationsAction;
use App\Platform\Ai\TenantAwareDatabaseConversationStore;
use App\Platform\Console\PruneModelsCommand;
use App\Platform\Contracts\Production\ReadinessCheck;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Policies\DataSubjectRequestPolicy;
use App\Platform\Services\Production\InfrastructureReadinessCheck;
use App\Platform\Services\Production\ProductionConfigurationValidator;
use App\Platform\Support\PlatformSupportSessionState;
use App\Shared\Support\Localization\TranslationDomains;
use App\Tenancy\Contracts\SupportSessionState;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Override;

use function is_string;

/**
 * Platform infrastructure bindings and production boot checks.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->commands([
            PruneModelsCommand::class,
        ]);

        $this->app->bind(ReadinessCheck::class, InfrastructureReadinessCheck::class);

        // Replaces the vendor DatabaseConversationStore so every persist and
        // lookup carries tenant_id. Read Tenant::current() per call — this is
        // a singleton and must not cache tenant state across Octane requests.
        $this->app->singleton(function (): ConversationStore {
            $connection = Config::get('ai.conversations.connection');

            return new TenantAwareDatabaseConversationStore(
                is_string($connection) ? $connection : null,
            );
        });

        // Wraps the HTTP session, so it must not outlive a request under Octane.
        $this->app->scoped(SupportSessionState::class, PlatformSupportSessionState::class);
        $this->app->singleton(LoadFrontendTranslationsAction::class);

        // Register domain JSON paths at loader construction — before any
        // load(). A provider booting earlier (Fortify is 2nd of 19) that
        // called __() would otherwise memoize an incomplete dictionary for
        // the rest of the request, and under Octane for the worker's life.
        // afterResolving also preserves TranslationServiceProvider's
        // deferral, which a direct Lang::addJsonPath() would defeat.
        $this->app->afterResolving('translation.loader', function (Loader $loader): void {
            foreach (TranslationDomains::NAMES as $domain) {
                $loader->addJsonPath(app_path("{$domain}/".TranslationDomains::RelativeDirectory));
            }
        });
    }

    public function boot(ProductionConfigurationValidator $productionConfigurationValidator): void
    {
        Gate::policy(DataSubjectRequest::class, DataSubjectRequestPolicy::class);

        // Surface missing __() keys outside production. The fallback is the key
        Lang::handleMissingKeysUsing(function (string $key, array $replace, string $locale): string {
            if (! $this->app->environment('production')) {
                Log::warning("Missing translation key [{$key}] for locale [{$locale}].");
            }

            return $key;
        });

        $productionConfigurationValidator->validate();
    }
}
