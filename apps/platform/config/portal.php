<?php

declare(strict_types=1);

use App\ClientPortal\Enums\NewPortalDeviceAction;

return [
    'session_lifetime_minutes' => (int) env('PORTAL_SESSION_LIFETIME', 15),

    /*
    |--------------------------------------------------------------------------
    | Redemption from a new device
    |--------------------------------------------------------------------------
    |
    | A grant token can be re-exchanged until it expires, so the same link may
    | legitimately be opened from a phone, a laptop, and an office network. This
    | decides what happens the first time a link is redeemed from a fingerprint
    | that does not match the one that redeemed it originally.
    |
    |   notify (default) — let the client in, tell the responsible staff member
    |   block           — refuse (as a 404, like every other portal denial)
    |   ignore          — record nothing
    |
    | `notify` is the default deliberately. Blocking would strand clients who
    | switch from mobile data to wifi, which is the support burden that made
    | single-use tokens untenable in the first place.
    |
    */
    'new_device_action' => env('PORTAL_NEW_DEVICE_ACTION', NewPortalDeviceAction::Notify->value),

    /*
    |--------------------------------------------------------------------------
    | Dashboard follow-up thresholds
    |--------------------------------------------------------------------------
    |
    | When the workspace dashboard starts calling a client "quiet". These are
    | presentation thresholds for a nudge, not enforcement — nothing expires or
    | is blocked because of them, so they stay generous by default.
    |
    */
    'follow_up' => [
        'never_opened_days' => (int) env('PORTAL_NEVER_OPENED_DAYS', 3),
        'stale_activity_days' => (int) env('PORTAL_STALE_ACTIVITY_DAYS', 7),
        'expiring_link_days' => (int) env('PORTAL_EXPIRING_LINK_DAYS', 7),
    ],
];
