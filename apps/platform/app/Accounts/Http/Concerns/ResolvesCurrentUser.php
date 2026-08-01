<?php

declare(strict_types=1);

namespace App\Accounts\Http\Concerns;

use App\Accounts\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the authenticated user as a concrete User inside a controller.
 *
 * Controllers repeatedly narrowed `$request->user()` from Authenticatable to User
 * to satisfy PHPStan, each with its own inline abort — across three different
 * spellings of 403. This centralises the guard and the status code.
 *
 * Lives in Accounts rather than on the shared base controller because App\Shared
 * must not depend on another domain (enforced in tests/Pest.php).
 *
 * @see EnsuresAuthenticatedUser for the FormRequest equivalent.
 */
trait ResolvesCurrentUser
{
    protected function currentUser(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        return $user;
    }
}
