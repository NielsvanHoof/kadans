<?php

declare(strict_types=1);

use App\Accounts\Enums\TwoFactorEnforcement;

return [

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication Enforcement
    |--------------------------------------------------------------------------
    |
    | Fortify makes 2FA available; this decides who has to use it. Enforcement
    | is applied by App\Accounts\Http\Middleware\RequireTwoFactorAuthentication
    | on the authenticated app and workspace route groups — never on settings or
    | auth routes, or an unenrolled user could not reach the enrolment page.
    |
    | Only a *confirmed* TOTP factor satisfies this. A registered passkey does
    | not: passkeys are an alternative login here, so a user holding one can
    | still sign in with a password alone and would have no second factor on
    | that path.
    |
    | `grace_days` is counted from account creation, giving a new hire time to
    | enrol. Set it to 0 to require enrolment before the first page load.
    |
    | Production must not run with this `off` — ProductionConfigurationValidator
    | rejects it at boot.
    |
    */

    'two_factor' => [
        'enforcement' => env('SECURITY_TWO_FACTOR_ENFORCEMENT', TwoFactorEnforcement::AllStaff->value),

        // Only consulted when enforcement is `by_permission`.
        'permission' => env('SECURITY_TWO_FACTOR_PERMISSION', 'documents.download'),

        'grace_days' => (int) env('SECURITY_TWO_FACTOR_GRACE_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | The policy is assembled per request by App\Shared\Http\ContentSecurityPolicy
    | so that the Vite nonce, the Vite dev server, the Reverb websocket, and the
    | S3 document host are all derived from existing configuration instead of
    | being duplicated here.
    |
    | Set `report_only` to send Content-Security-Policy-Report-Only instead of
    | enforcing — useful when rolling out a tightened policy.
    |
    */

    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
        'report_uri' => env('SECURITY_CSP_REPORT_URI'),

        /*
         * Extra origins appended to the matching directive. Use for third
         * parties that are not derivable from config (analytics, a CDN, …).
         *
         * Example: ['connect-src' => ['https://o0.ingest.sentry.io']]
         *
         * @var array<string, list<string>>
         */
        'extra_sources' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Only emitted on HTTPS requests — sending HSTS over plain HTTP is ignored
    | by browsers and would break local development.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        // Opt in only once the domain is ready for the preload list — it is hard to undo.
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions Policy
    |--------------------------------------------------------------------------
    |
    | Denies browser features the platform never uses. Kept as a single string
    | so operators can override it without a deploy.
    |
    */

    'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', implode(', ', [
        'accelerometer=()',
        'autoplay=()',
        'browsing-topics=()',
        'camera=()',
        'display-capture=()',
        'encrypted-media=()',
        'geolocation=()',
        'gyroscope=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'payment=()',
        'screen-wake-lock=()',
        'usb=()',
        'xr-spatial-tracking=()',
    ])),

    /*
    |--------------------------------------------------------------------------
    | Referrer Policy
    |--------------------------------------------------------------------------
    |
    | The client portal overrides this with `no-referrer` in
    | App\ClientPortal\Http\Middleware\HardenClientPortalResponse.
    | PHP owns this header — docker/production/Caddyfile must not set it.
    |
    */

    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

];
