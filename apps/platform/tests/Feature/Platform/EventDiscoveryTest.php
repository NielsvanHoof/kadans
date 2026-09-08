<?php

declare(strict_types=1);

use App\ClientPortal\Listeners\Outreach\LogClientPortalInviteFailed;
use App\ClientPortal\Listeners\Outreach\LogClientPortalInviteSent;
use App\Tenancy\Listeners\Workspace\AddCurrentTenantToContext;
use App\Tenancy\Listeners\Workspace\RemoveCurrentTenantFromContext;
use Spatie\Multitenancy\Events\ForgotCurrentTenantEvent;
use Spatie\Multitenancy\Events\MadeTenantCurrentEvent;

test('domain event listeners are discovered', function (): void {
    $this->artisan('event:list')
        ->expectsOutputToContain(LogClientPortalInviteSent::class)
        ->expectsOutputToContain(LogClientPortalInviteFailed::class)
        // Observability stamps: type hints on handle() are load-bearing for discovery.
        ->expectsOutputToContain(AddCurrentTenantToContext::class)
        ->expectsOutputToContain(RemoveCurrentTenantFromContext::class)
        ->expectsOutputToContain(MadeTenantCurrentEvent::class)
        ->expectsOutputToContain(ForgotCurrentTenantEvent::class)
        ->assertSuccessful();
});
