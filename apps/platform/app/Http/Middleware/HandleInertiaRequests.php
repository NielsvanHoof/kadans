<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Localization\LoadFrontendTranslationsAction;
use App\Data\Tenancy\WorkspaceNavItemData;
use App\Enums\Locale;
use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Queries\Notifications\FetchWorkspaceNotificationsForUserQuery;
use App\Queries\Tenancy\FetchWorkspaceNavigationForUserQuery;
use App\Support\Platform\SupportSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly FetchWorkspaceNavigationForUserQuery $getWorkspaceNavigationForUser,
        private readonly FetchWorkspaceNotificationsForUserQuery $getWorkspaceNotificationsForUser,
        private readonly LoadFrontendTranslationsAction $loadFrontendTranslations,
        private readonly SupportSession $supportSession,
    ) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
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
    public function share(Request $request): array
    {
        $user = $request->user();

        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'available_locales' => array_map(
                fn (Locale $availableLocale): array => [
                    'code' => $availableLocale->value,
                    'label' => $availableLocale->label(),
                ],
                Locale::cases(),
            ),
            'translations' => fn (): array => $this->loadFrontendTranslations->handle($locale),
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
            'support_session' => function () use ($user): ?array {
                if (! $user instanceof User || ! $user->isPlatformSupport()) {
                    return null;
                }

                $session = $this->supportSession->active();

                if ($session === null) {
                    return null;
                }

                $tenant = Tenant::query()->find($session->tenantId);

                if (! $tenant instanceof Tenant) {
                    return null;
                }

                return [
                    'tenant' => [
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                    ],
                    'reason' => $session->reason,
                    'expires_at' => $session->expiresAt->toIso8601String(),
                ];
            },
            'workspaces' => $user instanceof User
                ? Inertia::once(
                    fn () => array_map(
                        static fn (WorkspaceNavItemData $item): array => $item->toArray(),
                        $this->getWorkspaceNavigationForUser->handle($user),
                    ),
                )->fresh(
                    $request->hasSession()
                        ? $request->session()->pull('inertia.refresh.workspaces', false)
                        : false,
                )
                : [],
            'current_firm' => function (): ?array {
                $tenant = Tenant::current();

                if (! $tenant instanceof Tenant) {
                    return null;
                }

                return [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ];
            },
            'can_manage_members' => function () use ($user): bool {
                $tenant = Tenant::current();

                return $user instanceof User
                    && $tenant instanceof Tenant
                    && $user->hasTenantPermission(Permission::ManageMembers, $tenant);
            },
            'can_view_pipelines' => function () use ($user): bool {
                $tenant = Tenant::current();

                return $user instanceof User
                    && $tenant instanceof Tenant
                    && $user->hasTenantPermission(Permission::ViewAuditLog, $tenant);
            },
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
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
