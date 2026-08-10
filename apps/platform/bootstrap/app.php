<?php

declare(strict_types=1);

use App\Accounts\Http\Middleware\SetLocale;
use App\Audit\Actions\LogAccessDeniedAction;
use App\ClientPortal\Http\Middleware\HardenClientPortalResponse;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\Dossiers\Support\DocumentUploadLimits;
use App\Platform\Http\Middleware\HandleInertiaRequests;
use App\Shared\Http\Middleware\AddSecurityHeaders;
use App\Shared\Http\Middleware\HandleAppearance;
use App\Shared\Http\Middleware\TrackRequestContext;
use App\Tenancy\Http\Middleware\InitializeTenantFromRoute;
use App\Tenancy\Http\Middleware\InitializeTenantFromSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\SessionManager;
use Inertia\ExceptionResponse;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    ->withCommands([
        __DIR__.'/../app/AudioProcessing/Console/Commands',
        __DIR__.'/../app/Dossiers/Console/Commands',
        __DIR__.'/../app/DocumentProcessing/Console/Commands',
        __DIR__.'/../app/Pipelines/Console/Commands',
        __DIR__.'/../app/Platform/Console/Commands',
        __DIR__.'/../app/Questionnaires/Console/Commands',
        __DIR__.'/../app/Tenancy/Console/Commands',
    ])
    ->withEvents(discover: [
        __DIR__.'/../app/*/Listeners',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(
            at: fn (): array => (array) config('production.allowed_hosts', []),
            subdomains: false,
        );
        $middleware->trustProxies(
            headers: Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_AWS_ELB,
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
            // Before everything else: later middleware, controllers, and jobs
            // dispatched from them all log under this request's correlation id.
            TrackRequestContext::class,
            // First in, last out: generates the Vite nonce before the root view
            // renders, then writes CSP and friends onto the finished response.
            AddSecurityHeaders::class,
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Audit refused authorization attempts. A render hook (not Gate::after)
        // because only an AuthorizationException means an attempt was actually
        // refused rather than a capability probed for a `can_*` prop — see
        // LogAccessDeniedAction. $exceptions->report() cannot be used either:
        // AuthorizationException is in the framework's internalDontReport.
        //
        // Typed on Symfony's HttpException, not AuthorizationException, because
        // Handler::render() runs prepareException() *before* the render callbacks:
        // by this point a policy denial is already an AccessDeniedHttpException
        // (or a plain HttpException when the policy set an explicit status), with
        // the original as its previous. Callbacks typed on AuthorizationException
        // are never reached. abort()/abort_unless() throw HttpException with no
        // previous, so they fall through the instanceof below and stay unlogged —
        // deliberate: they are not policy checks, and the portal conceals
        // cross-dossier probes as 404s that must not fill the trail.
        $exceptions->render(function (HttpException $exception, Request $request): null {
            $denial = $exception->getPrevious();

            if (! $denial instanceof AuthorizationException) {
                return null;
            }

            try {
                resolve(LogAccessDeniedAction::class)->handle($denial, $request);
            } catch (Throwable $auditFailure) {
                // Already on a degraded path; surface the failed trail write
                // without turning the user's 403 into a 500.
                report($auditFailure);
            }

            return null;
        });

        // Oversized POSTs never reach FormRequest validation — PHP empties the
        // body — so name the limit explicitly instead of a CSRF/missing-file error.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $message = __('The upload is too large. The maximum total size is :size.', [
                'size' => DocumentUploadLimits::MAX_POST_SIZE_LABEL,
            ]);

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 413);
            }

            // ValidatePostSize runs in the global stack before StartSession.
            // Re-bind the existing session cookie so flashed errors persist for
            // the follow-up GET (the browser already holds the cookie).
            if (! $request->hasSession()) {
                $session = resolve(SessionManager::class)->driver();
                $session->setId($request->cookies->get($session->getName()));
                $session->setRequestOnHandler($request);
                $session->start();
                $request->setLaravelSession($session);
            }

            $redirect = back()->withErrors(['documents' => $message]);
            $request->session()->save();

            return $redirect;
        });

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
                $response = new ExceptionResponse(
                    $exception,
                    $request,
                    $response,
                    resolve(Router::class),
                    resolve(KernelContract::class),
                )
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
