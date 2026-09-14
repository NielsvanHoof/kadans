<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Profile;

use App\Accounts\Models\User;

/**
 * Persist profile field changes from the settings form.
 */
final readonly class UpdateUserProfileAction
{
    /**
     * @param  array{name?: string, email?: string}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        $user->fill($attributes);

        // Changing email invalidates verification until the new address confirms.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user;
    }
}
