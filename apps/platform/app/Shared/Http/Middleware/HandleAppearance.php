<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

use function in_array;
use function is_string;

final class HandleAppearance
{
    /** @var list<string> */
    private const array AllowedAppearances = ['light', 'dark', 'system'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Cookie is unencrypted and interpolated into the dark-mode bootstrap
        // script — only the three client-known values may reach the view.
        $appearance = $request->cookie('appearance');
        $safeAppearance = is_string($appearance) && in_array($appearance, self::AllowedAppearances, true)
            ? $appearance
            : 'system';

        View::share('appearance', $safeAppearance);

        return $next($request);
    }
}
