<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HardenClientPortalResponse;
use App\Http\Middleware\InitializeTenantFromRoute;
use App\Http\Middleware\InitializeTenantFromSession;
use App\Http\Middleware\ResolveClientPortalGrant;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Inertia\ExceptionResponse;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/health.php';
            require __DIR__.'/../routes/tenant.php';
            require __DIR__.'/../routes/portal.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(
            at: fn (): array => (array) config('production.allowed_hosts', []),
            subdomains: false,
        );
        $middleware->trustProxies(
            at: 'REMOTE_ADDR',
            headers: Request::HEADER_X_FORWARDED_AWS_ELB,
        );
        $middleware->encryptCookies(except: ['appearance', 'locale', 'sidebar_state']);

        // Tenant must be current before route model binding so BelongsToTenant scopes apply.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: InitializeTenantFromSession::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: InitializeTenantFromRoute::class,
        );

        // Restricted portal sessions must resolve tenant before tenant-scoped bindings.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveClientPortalGrant::class,
        );

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Register once here: Laravel keeps a single finalizeResponseCallback.
        // Resolving the handler from the container fails under Collision (dev),
        // which wraps the contract and does not expose respondUsing().
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            $status = $response->getStatusCode();

            // Keep Laravel's debug pages for 500/503 while developing.
            $statuses = app()->hasDebugModeEnabled()
                ? [403, 404]
                : [403, 404, 500, 503];

            // Leave JSON (and non-Inertia API-style) error payloads alone.
            if (
                ! $request->expectsJson()
                && in_array($status, $statuses, true)
            ) {
                $response = (new ExceptionResponse(
                    $exception,
                    $request,
                    $response,
                    app(Router::class),
                    app(KernelContract::class),
                ))
                    ->render('error', ['status' => $status])
                    ->withSharedData()
                    ->usingMiddleware(HandleInertiaRequests::class)
                    ->toResponse($request);
            }

            if ($request->is('portal/*')) {
                return HardenClientPortalResponse::applyHeaders($response);
            }

            return $response;
        });

        Integration::handles($exceptions);
    })->create();
