<?php

declare(strict_types=1);

namespace App\Accounts\Http\Concerns;

use App\Accounts\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * FormRequest authorization for routes that only need *any* signed-in user.
 *
 * Named for what it grants rather than what it checks: authorize() here is an
 * authentication test, not a policy check, and satisfying FormRequest's contract
 * with one is easy to misread as "this route is authorized". Routes using this
 * trait are the ones listed under `current-user-only` in
 * tests/Feature/AuthorizationCoverageTest.php — they touch nothing but the
 * current user's own data.
 *
 * If a route needs a policy, do not reach for this trait: implement authorize()
 * with a Gate call so the coverage test sees it.
 *
 * @see ResolvesCurrentUser for the controller equivalent of authenticatedUser().
 */
trait AuthorizesAnyAuthenticatedUser
{
    /**
     * Any authenticated user may proceed; no resource is being authorized.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * The authenticated user, narrowed from Authenticatable for static analysis.
     */
    public function authenticatedUser(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        return $user;
    }
}
