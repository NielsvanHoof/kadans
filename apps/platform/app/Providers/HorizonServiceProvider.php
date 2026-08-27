<?php

declare(strict_types=1);

namespace App\Providers;

use App\Accounts\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

use function in_array;
use function is_string;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments,
     * and it is the only thing that does. Horizon renders queue payloads and
     * failed-job exception traces, and it is not tenant-scoped — this
     * application's jobs carry dossier, document and tenant identifiers, and
     * GenerateDataSubjectExportJob handles GDPR exports. An open dashboard
     * therefore breaks the first line of the threat model: one firm reading
     * another firm's data.
     *
     * Empty allowlist denies everyone. That is the safe direction for a
     * dashboard nobody needs in order to serve traffic, and
     * ProductionConfigurationValidator refuses to boot a shipped environment
     * with no entries rather than leaving it silently unreachable.
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (User $user): bool {
            $allowed = array_values(array_filter(
                Config::array('horizon.allowed_emails'),
                static fn (mixed $email): bool => is_string($email) && $email !== '',
            ));

            return in_array($user->email, $allowed, true);
        });
    }
}
