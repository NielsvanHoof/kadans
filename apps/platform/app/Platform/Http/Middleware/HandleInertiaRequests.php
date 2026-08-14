<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Accounts\Actions\Localization\ResolveApplicationTimezoneAction;
use App\Accounts\Models\User;
use App\Platform\Http\SharedProps\LocaleProps;
use App\Platform\Http\SharedProps\SupportSessionProps;
use App\Platform\Http\SharedProps\TenantPermissionProps;
use App\Platform\Http\SharedProps\TimezoneProps;
use App\Platform\Http\SharedProps\WorkspaceProps;
use App\Tenancy\Models\Tenant;
use App\WorkspaceNotifications\Queries\FetchWorkspaceNotificationsForUserQuery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;
use Override;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    #[Override]
    protected $rootView = 'app';

    public function __construct(
        private readonly FetchWorkspaceNotificationsForUserQuery $getWorkspaceNotificationsForUser,
        private readonly LocaleProps $localeProps,
        private readonly ResolveApplicationTimezoneAction $resolveApplicationTimezone,
        private readonly SupportSessionProps $supportSessionProps,
        private readonly TenantPermissionProps $tenantPermissionProps,
        private readonly TimezoneProps $timezoneProps,
        private readonly WorkspaceProps $workspaceProps,
    ) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    #[Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Keep Spatie filter[key] brackets readable in the address bar.
     *
     * Request::fullUrl() percent-encodes [ and ] as %5B/%5D. Inertia syncs
     * page.url into history after each visit, so we only unescape brackets
     * and leave other encodings (e.g. %26) intact.
     *
     * @see https://inertiajs.com/docs/v3/the-basics/routing
     */
    public function urlResolver(): Closure
    {
        return function (Request $request): string {
            $url = Str::start(
                Str::after($request->fullUrl(), $request->getSchemeAndHttpHost()),
                '/',
            );

            return str_replace(['%5B', '%5D'], ['[', ']'], $url);
        };
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        $user = $request->user();

        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => Config::string('app.name'),
            ...($this->localeProps)($locale),
            ...($this->timezoneProps)($this->resolveApplicationTimezone->handle($request)),
            'auth' => [
                'user' => $user instanceof User
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'locale' => $user->getAttributes()['locale'] ?? null,
                        'is_platform_support' => $user->isPlatformSupport(),
                    ]
                    : null,
            ],
            'support_session' => fn (): ?array => ($this->supportSessionProps)($user),
            ...($this->workspaceProps)($request, $user),
            // Whole registered permission set for the current firm — the frontend
            // gates on this rather than on per-feature booleans defined here.
            'permissions' => fn (): array => ($this->tenantPermissionProps)($user),
            // Staff bell inbox — deferred so non-bell pages stay cheap.
            'notifications' => (function () use ($user) {
                $tenant = Tenant::current();

                if (! $user instanceof User || ! $tenant instanceof Tenant) {
                    return null;
                }

                return Inertia::defer(
                    fn (): array => $this->getWorkspaceNotificationsForUser->handle($user, $tenant)->toArray(),
                );
            })(),
            'sidebar_open' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
