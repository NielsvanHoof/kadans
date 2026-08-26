<?php

declare(strict_types=1);

namespace App\Providers;

use App\Accounts\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

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
     * This gate determines who can access Horizon in non-local environments.
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (User $user): bool {
            return true;
            // $allowed = Config::array('horizon.allowed_emails');

            // return in_array($user->email, array_values(array_filter(
            //     $allowed,
            //     static fn (mixed $email): bool => is_string($email) && $email !== '',
            // )), true);
        });
    }
}
