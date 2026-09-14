<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Localization;

use App\Accounts\Models\User;

/**
 * Persist the user's preferred timezone.
 */
final readonly class UpdateUserTimezoneAction
{
    public function handle(User $user, string $timezone): void
    {
        $user->update(['timezone' => $timezone]);
    }
}
