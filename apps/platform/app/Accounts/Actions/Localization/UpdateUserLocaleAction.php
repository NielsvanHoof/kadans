<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Localization;

use App\Accounts\Models\User;

/**
 * Persist the user's preferred UI locale.
 */
final readonly class UpdateUserLocaleAction
{
    public function handle(User $user, string $locale): void
    {
        $user->update(['locale' => $locale]);
    }
}
