<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'token' => env('AWS_SESSION_TOKEN'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'endpoint' => env('AWS_ENDPOINT'),
    ],

    /*
     * The browser Sentry SDK's DSN, which is a separate project from the PHP
     * one in config/sentry.php. Vite inlines this same VITE_SENTRY_DSN into the
     * bundle at build time — that is the copy the SDK actually uses. It is
     * repeated here because the server needs it for two things the bundle
     * cannot do: allow the ingest origin in the CSP, and refuse to boot
     * production with browser error reporting switched off.
     *
     * It deliberately does not live in config/sentry.php: every key in that
     * file is handed to the PHP SDK's Options object, which throws on any it
     * does not recognise.
     */
    'sentry' => [
        'browser_dsn' => env('VITE_SENTRY_DSN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Google OAuth for per-user sign-in (built-in Socialite driver).
     * Optional: leave GOOGLE_CLIENT_ID empty to hide "Log in with Google".
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    /*
     * Microsoft Entra (Azure AD) OAuth for per-user sign-in and Graph tokens.
     * Optional: leave MICROSOFT_CLIENT_ID empty to hide "Log in with Microsoft".
     * tenant=organizations rejects personal Microsoft accounts.
     * Foundation kept for Graph/calendar later; Google is the free default SSO.
     */
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', '/auth/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'organizations'),
    ],

];
