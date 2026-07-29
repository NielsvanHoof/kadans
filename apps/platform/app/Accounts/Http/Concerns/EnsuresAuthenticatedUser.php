<?php

declare(strict_types=1);

namespace App\Accounts\Http\Concerns;

use App\Accounts\Models\User;

trait EnsuresAuthenticatedUser
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function authenticatedUser(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
