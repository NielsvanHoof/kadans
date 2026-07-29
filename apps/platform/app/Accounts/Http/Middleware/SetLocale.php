<?php

declare(strict_types=1);

namespace App\Accounts\Http\Middleware;

use App\Accounts\Actions\Localization\ResolveApplicationLocaleAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetLocale
{
    public function __construct(
        private ResolveApplicationLocaleAction $resolveApplicationLocale,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveApplicationLocale->handle($request));

        return $next($request);
    }
}
