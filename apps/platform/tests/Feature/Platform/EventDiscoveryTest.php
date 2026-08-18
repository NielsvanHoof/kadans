<?php

declare(strict_types=1);

use App\ClientPortal\Listeners\LogClientPortalInviteFailed;
use App\ClientPortal\Listeners\LogClientPortalInviteSent;
use App\Tenancy\Listeners\AddCurrentTenantToContext;
use App\Tenancy\Listeners\RemoveCurrentTenantFromContext;
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
