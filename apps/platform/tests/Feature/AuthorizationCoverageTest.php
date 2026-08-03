<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authorization coverage
|--------------------------------------------------------------------------
|
| The default convention: a route carrying a FormRequest authorizes in that
| request's authorize() via the Gate; otherwise the controller action calls
| $this->authorize(). This test detects that automatically.
|
| Not every route can use the Gate. Guest portal clients are not Users and have
| no policies, platform support is a predicate on the user, and some routes only
| ever touch the current user's own data. Those routes are listed below with the
| mechanism that protects them instead — this map is documentation, not a
| dumping ground.
|
| The assertion is an exact match, so the map rots in neither direction: a new
| route with no protection at all fails, and so does an entry that has since
| moved to the Gate.
|
*/

/** @var array<string, string> route name => mechanism protecting it instead of the Gate */
const AUTHORIZATION_NOT_VIA_GATE = [
    // current-user-only — no other actor's resource is reachable.
    'dashboard' => 'current-user-only: own workspace dashboard.',
    'firms.create' => 'current-user-only: form for creating your own firm.',
    'firms.store' => 'current-user-only: any authenticated user may create a firm.',
    'onboarding.firm.create' => 'current-user-only: first-run firm setup.',
    'onboarding.firm.store' => 'current-user-only: first-run firm setup.',
    'language.edit' => 'current-user-only: own locale preference.',
    'language.update' => 'current-user-only: own locale preference.',
    'profile.edit' => 'current-user-only: own profile.',
    'profile.update' => 'current-user-only: own profile.',
    'profile.destroy' => 'current-user-only: own account.',
    'security.edit' => 'current-user-only: own security settings.',
    'user-password.update' => 'current-user-only: own password (Fortify).',

    // membership-check — inline User::canAccessTenant rather than a policy.
    'firms.activate' => 'membership-check: ActiveTenantController asserts User::canAccessTenant.',

    // middleware — the route group is gated before the controller runs.
    'platform.support.index' => 'middleware: EnsurePlatformSupport.',
    'platform.support.destroy' => 'middleware: EnsurePlatformSupport.',

    // request-predicate — authorize() uses a domain predicate, not the Gate.
    'platform.support.store' => 'request-predicate: StartSupportSessionRequest asserts User::isPlatformSupport.',

    // portal-grant — guest clients are not Users; the grant plus the transition
    // rules in DocumentRequestTransitions::canSubmit are the authorization.
    'portal.show' => 'portal-grant: ResolveClientPortalGrant middleware.',
    'portal.document-requests.answer' => 'portal-grant: grant + dossier match + canSubmit + field visibility.',
    'portal.document-requests.upload' => 'portal-grant: grant + dossier match + canSubmit.',

    // token-credential — a hashed/signed token is the credential, for guests.
    'invitations.show' => 'token-credential: invitation token.',
    'invitations.accept' => 'token-credential: invitation token.',
    'invitations.register' => 'token-credential: invitation token.',

    // query-scoped — the action filters by the owning user, so there is no IDOR.
    'workspaces.notifications.read' => 'query-scoped: filters on notifiable_id + tenant.',
    'workspaces.notifications.read-all' => 'query-scoped: filters on notifiable_id + tenant.',
];

/**
 * Read the literal source of a method, so we can see whether it authorizes.
 */
function methodSource(ReflectionMethod $method): string
{
    $file = $method->getFileName();
    $start = $method->getStartLine();
    $end = $method->getEndLine();

    if ($file === false || $start === false || $end === false) {
        return '';
    }

    $lines = file($file);

    if ($lines === false) {
        return '';
    }

    return implode('', array_slice($lines, $start - 1, $end - $start + 1));
}

/**
 * Does this source fragment consult the Gate?
 *
 * Positive detection on purpose. AuthorizesAnyAuthenticatedUser::authorize() only
 * asserts `$this->user() instanceof User`, which is authentication, not
 * authorization — it must not count, or every settings route would look guarded.
 */
function consultsGate(string $source): bool
{
    return array_any(['->can(', '->cannot(', 'Gate::', '->allows(', '->denies(', '$this->authorize('], fn (string $needle): bool => str_contains($source, $needle));
}

/**
 * Full source of a class plus every trait it composes.
 *
 * A FormRequest's authorize() often delegates to a helper in a shared trait
 * (AuthorizesDossierBuilderMutation), so reading authorize() alone under-reports.
 * A FormRequest maps to exactly one route, so widening to the whole class is safe
 * here — unlike a controller, where each action must be judged separately.
 */
function classSourceWithTraits(string $class): string
{
    $reflection = new ReflectionClass($class);
    $files = [];

    $file = $reflection->getFileName();

    if ($file !== false) {
        $files[$file] = true;
    }

    foreach ($reflection->getTraits() as $trait) {
        $traitFile = $trait->getFileName();

        if ($traitFile !== false) {
            $files[$traitFile] = true;
        }
    }

    $source = '';

    foreach (array_keys($files) as $path) {
        $contents = file_get_contents($path);

        if ($contents !== false) {
            $source .= $contents;
        }
    }

    return $source;
}

/**
 * Does this route reach the Gate, from its controller action or its FormRequest?
 */
function routeAuthorizesViaGate(RoutingRoute $route): bool
{
    $action = $route->getActionName();

    if (! str_contains($action, '@')) {
        return false;
    }

    [$class, $methodName] = explode('@', $action, 2);

    if (! class_exists($class) || ! method_exists($class, $methodName)) {
        return false;
    }

    $method = new ReflectionMethod($class, $methodName);

    if (consultsGate(methodSource($method))) {
        return true;
    }

    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            continue;
        }
        if ($type->isBuiltin()) {
            continue;
        }

        $parameterClass = $type->getName();
        if (! class_exists($parameterClass)) {
            continue;
        }
        if (! is_subclass_of($parameterClass, FormRequest::class)) {
            continue;
        }

        if (consultsGate(classSourceWithTraits($parameterClass))) {
            return true;
        }
    }

    return false;
}

test('every application route reaches the Gate or declares another mechanism', function (): void {
    $notViaGate = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getActionName();
        if (! str_contains((string) $action, '@')) {
            continue;
        }
        if (! str_starts_with((string) $action, 'App\\')) {
            continue;
        }

        if (routeAuthorizesViaGate($route)) {
            continue;
        }

        $notViaGate[] = $route->getName() ?? $action;
    }

    $notViaGate = array_unique($notViaGate);
    sort($notViaGate);

    $declared = array_keys(AUTHORIZATION_NOT_VIA_GATE);
    sort($declared);

    expect($notViaGate)->toBe(
        $declared,
        'A route that does not reach the Gate must be listed in AUTHORIZATION_NOT_VIA_GATE with the '
        .'mechanism that protects it instead. Remove entries that now use the Gate.',
    );
});

test('every declared mechanism names a route that still exists', function (): void {
    $names = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if ($name !== null) {
            $names[] = $name;
        }
    }

    foreach (array_keys(AUTHORIZATION_NOT_VIA_GATE) as $declaredRoute) {
        expect($names)->toContain($declaredRoute);
    }
});
