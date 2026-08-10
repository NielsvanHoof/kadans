<?php

declare(strict_types=1);

namespace App\Accounts\Http\Middleware;

use App\Accounts\Enums\TwoFactorEnforcement;
use App\Accounts\Models\User;
use App\Shared\Http\Toast;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

use function is_string;

/**
 * Send staff without a second factor to the enrolment page.
 *
 * Applied to the authenticated app and workspace route groups only. Settings
 * and auth routes are deliberately left out rather than exempted by name: the
 * enrolment page itself lives under settings, and an exemption list keyed on
 * Fortify's route names would silently rot into a lockout the first time the
 * package renamed one.
 *
 * Enforcement is skipped entirely when the Fortify feature is disabled, so
 * turning 2FA off cannot leave staff redirected at a page that no longer works.
 */
final readonly class RequireTwoFactorAuthentication
{
    public function __construct(private Repository $config) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->shouldEnforceFor($user)) {
            return $next($request);
        }

        // XHR callers (the live transcript poll, the notification inbox) would
        // follow a redirect and render the settings page into a JSON slot.
        abort_if($request->expectsJson(), Response::HTTP_FORBIDDEN, __('Two-factor authentication is required for this account.'));

        Toast::warning(__('Set up two-factor authentication to continue.'));

        return to_route('security.edit');
    }

    private function shouldEnforceFor(User $user): bool
    {
        if (! Features::canManageTwoFactorAuthentication()) {
            return false;
        }

        // Confirmed only — Fortify's `confirm` option is on, so a half-finished
        // enrolment leaves a secret with no confirmed_at and does not count.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        return match ($this->enforcement()) {
            TwoFactorEnforcement::Off => false,
            TwoFactorEnforcement::AllStaff => ! $this->isWithinGracePeriod($user),
            TwoFactorEnforcement::ByPermission => $this->holdsGatedPermission($user)
                && ! $this->isWithinGracePeriod($user),
        };
    }

    private function enforcement(): TwoFactorEnforcement
    {
        $configured = $this->config->get('security.two_factor.enforcement');

        // An unrecognised value must fail closed: a typo in the deploy
        // environment cannot be allowed to silently disable enforcement.
        return TwoFactorEnforcement::tryFrom(is_string($configured) ? $configured : '')
            ?? TwoFactorEnforcement::AllStaff;
    }

    /**
     * Evaluated against the current workspace, which is the whole point of this
     * mode: a user who can download documents for one firm must hold a second
     * factor when working in that firm.
     */
    private function holdsGatedPermission(User $user): bool
    {
        $permission = $this->config->get('security.two_factor.permission');

        return is_string($permission) && $permission !== '' && $user->can($permission);
    }

    private function isWithinGracePeriod(User $user): bool
    {
        $graceDays = (int) $this->config->get('security.two_factor.grace_days');

        if ($graceDays <= 0 || $user->created_at === null) {
            return false;
        }

        return $user->created_at->addDays($graceDays)->isAfter(Date::now());
    }
}
