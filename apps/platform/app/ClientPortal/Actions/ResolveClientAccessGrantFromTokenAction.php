<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions;

use App\ClientPortal\Models\ClientAccessGrant;
use Illuminate\Support\Timebox;

final readonly class ResolveClientAccessGrantFromTokenAction
{
    /**
     * Every denial is already concealed as a 404, so response time is the last
     * channel that could separate an unknown token from a revoked or expired
     * one — an empty token returns before any query at all, and a hit costs a
     * row read a miss does not. Held to a fixed floor so all four outcomes look
     * alike. Matches the 200ms Laravel's own SessionGuard uses for auth.
     */
    private const int TIMEBOX_MICROSECONDS = 200_000;

    public function __construct(private Timebox $timebox) {}

    /**
     * Look up a valid grant by the plain token from the magic link.
     * Bypasses the tenant global scope because no tenant is current yet.
     */
    public function handle(string $plainTextToken): ?ClientAccessGrant
    {
        return $this->timebox->call(
            function () use ($plainTextToken): ?ClientAccessGrant {
                if ($plainTextToken === '') {
                    return null;
                }

                // queryWithoutTenantScope: portal magic links resolve before a tenant is current.
                return ClientAccessGrant::queryWithoutTenantScope()
                    ->where('token', ClientAccessGrant::hashToken($plainTextToken))
                    ->where('revoked_at', null)
                    ->where('expires_at', '>', now())
                    ->first();
            },
            self::TIMEBOX_MICROSECONDS,
        );
    }
}
