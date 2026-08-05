<?php

declare(strict_types=1);

use App\ClientPortal\Listeners\LogClientPortalInviteFailed;
use App\ClientPortal\Listeners\LogClientPortalInviteSent;

test('domain event listeners are discovered', function (): void {
    $this->artisan('event:list')
        ->expectsOutputToContain(LogClientPortalInviteSent::class)
        ->expectsOutputToContain(LogClientPortalInviteFailed::class)
        ->assertSuccessful();
});
