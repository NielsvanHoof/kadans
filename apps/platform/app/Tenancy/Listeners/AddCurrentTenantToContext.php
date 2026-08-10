<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Shared\Observability\ObservabilityContext;
use App\Tenancy\Models\Tenant;
use Spatie\Multitenancy\Events\MadeTenantCurrentEvent;

/**
 * Stamp the tenant onto logs and queued jobs the moment it becomes current.
 *
 * Hooking the event rather than a middleware covers every entry point at once:
 * workspace and portal requests, TenantContext::runForTenant() in console and
 * queue code, and anything else that calls makeCurrent().
 */
final readonly class AddCurrentTenantToContext
{
    public function handle(MadeTenantCurrentEvent $event): void
    {
        if (! $event->tenant instanceof Tenant) {
            return;
        }

        ObservabilityContext::rememberTenant($event->tenant->getKey());
    }
}
