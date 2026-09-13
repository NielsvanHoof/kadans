<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Models\User;
use App\Platform\Support\SupportSession\SupportSession;
use App\Platform\Support\SupportSession\SupportSessionData;
use App\Tenancy\Models\Tenant;

/**
 * Banner data for an active platform support session, or null.
 */
final readonly class SupportSessionProps
{
    public function __construct(private SupportSession $supportSession) {}

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(?User $user): ?array
    {
        if (! $user instanceof User || ! $user->isPlatformSupport()) {
            return null;
        }

        $session = $this->supportSession->active();

        if (! $session instanceof SupportSessionData) {
            return null;
        }

        $tenant = Tenant::query()->find($session->tenantId);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'reason' => $session->reason,
            'expires_at' => $session->expiresAt->toIso8601String(),
        ];
    }
}
