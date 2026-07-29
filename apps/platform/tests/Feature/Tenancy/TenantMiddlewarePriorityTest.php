<?php

declare(strict_types=1);

use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\Tenancy\Http\Middleware\InitializeTenantFromRoute;
use App\Tenancy\Http\Middleware\InitializeTenantFromSession;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;

test('tenant initialization middleware runs before route model binding', function (): void {
    /** @var Kernel $kernel */
    $kernel = resolve(Kernel::class);

    $priorityProperty = new ReflectionProperty(Kernel::class, 'middlewarePriority');

    /** @var list<class-string> $priority */
    $priority = $priorityProperty->getValue($kernel);

    $substituteBindingsIndex = array_search(SubstituteBindings::class, $priority, true);

    // array_search() returns int|false — not a boolean.
    expect($substituteBindingsIndex)->toBeInt();

    $tenantMiddleware = [
        InitializeTenantFromSession::class,
        InitializeTenantFromRoute::class,
        ResolveClientPortalGrant::class,
    ];

    foreach ($tenantMiddleware as $middleware) {
        $index = array_search($middleware, $priority, true);

        expect($index)->toBeInt()
            ->and($index)->toBeLessThan($substituteBindingsIndex);
    }

    expect(array_search(InitializeTenantFromSession::class, $priority, true))
        ->toBeLessThan(array_search(InitializeTenantFromRoute::class, $priority, true))
        ->and(array_search(InitializeTenantFromRoute::class, $priority, true))
        ->toBeLessThan(array_search(ResolveClientPortalGrant::class, $priority, true));
});
