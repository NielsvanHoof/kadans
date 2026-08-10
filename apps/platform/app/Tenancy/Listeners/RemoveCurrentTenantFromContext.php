<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Shared\Observability\ObservabilityContext;
use Spatie\Multitenancy\Events\ForgotCurrentTenantEvent;

/**
 * Drop the tenant stamp when the tenant is forgotten.
 *
 * Without this, a console run that switches between tenants — the retention
 * purge, the SQS consumers — would attribute later log records to whichever
 * tenant it happened to touch last.
 */
final readonly class RemoveCurrentTenantFromContext
{
    /**
     * The parameter is unused but must stay: `withEvents(discover: …)` maps a
     * listener to its event through the type hint on `handle()`. Dropping it
     * makes this class unreachable — the tenant stamp then never clears, and
     * nothing fails to say so.
     */
     public function handle(ForgotCurrentTenantEvent $event): void    {
        ObservabilityContext::forgetTenant();
    }
}
