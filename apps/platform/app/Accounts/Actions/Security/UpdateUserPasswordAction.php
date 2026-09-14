<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Security;

use App\Accounts\Models\User;

/**
 * Replace the signed-in user's password (already validated by the Form Request).
 */
final readonly class UpdateUserPasswordAction
{
    public function handle(User $user, string $password): void
    {
        $user->update([
            'password' => $password,
        ]);
    }
}
