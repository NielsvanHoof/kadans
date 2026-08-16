---
id: TASK-3.3
title: 'Harden Reverb: real secret, restricted origins, TLS'
status: Done
assignee:
  - '@claude'
created_date: '2026-08-06 09:50'
updated_date: '2026-08-06 10:41'
labels: []
dependencies: []
references:
  - config/reverb.php
  - .env.prod.example
  - app/Platform/Services/Production/ProductionConfigurationValidator.php
  - routes/channels.php
parent_task_id: TASK-3
priority: high
type: bug
ordinal: 11000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The production env template ships the development Reverb app key and secret and sets the scheme to http, and `allowed_origins` is a wildcard.

Pusher-protocol private channel authorisation is an HMAC of the app secret over socket id and channel name. With a known secret an attacker connects to the Reverb server from any origin and self-signs a subscribe for a private workspace channel for any firm slug. This bypasses the channel authorisation callbacks entirely, because the membership check only runs on the Laravel broadcasting auth route. Firm slugs are guessable, and the portal currently leaks the slug in shared Inertia props.

Impact today is cross-tenant read of pipeline events and arbitrary event injection into any firm UI. The payload is thin now, but the weakness silently inherits any future richer payload. The http scheme also means unencrypted websockets.

ProductionConfigurationValidator currently only asserts that the broadcast driver is reverb - it never checks the secret or the scheme.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The production Reverb app key and secret are environment-specific and are not the development values
- [x] #2 allowed_origins is restricted to the application host rather than a wildcard
- [x] #3 The production Reverb scheme is https and websockets are encrypted
- [x] #4 ProductionConfigurationValidator fails boot when the Reverb secret is a known default or the scheme is not https
- [x] #5 A test covers the validator additions
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Discovered .env.prod.example (referenced by the task) does not exist anywhere in the repo — no production env template to fix. The real, enforceable fix is config/reverb.php (runtime code) plus ProductionConfigurationValidator (the mechanism that fails boot on bad values), so proceeded with those instead of inventing a template file.
2. Fix config/reverb.php: allowed_origins was hardcoded to ['*'] regardless of environment; changed it to derive from APP_URL's host the same way config/production.php derives allowed_hosts, so it is never a wildcard in any environment.
3. Extend ProductionConfigurationValidator with four new checks: Reverb app key is set and is not the checked-in dev value ('local-reverb-key'), Reverb app secret is set and is not the checked-in dev value ('local-reverb-secret'), Reverb scheme is 'https', and Reverb TLS (useTLS) is enabled. Kept scheme and useTLS as two separate checks (not one combined check) so the reported invalid key always matches the specific broken value, consistent with every other check in this validator.
4. Add a wildcard/empty check for reverb allowed_origins too, extending the same 'make the regression impossible' pattern already used for production.allowed_hosts in TASK-3.1, even though AC #4 only names secret/scheme explicitly.
5. Extend ProductionConfigurationValidatorTest with a Reverb-safe baseline plus 8 new failing-dataset cases (missing/dev key, missing/dev secret, non-https scheme, TLS disabled, wildcard/empty origins).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Confirmed via 'vendor/bin/sail artisan config:cache' that the new computed $applicationHost variable at the top of config/reverb.php (mirroring config/production.php's pattern) caches and clears cleanly.

Split the scheme+useTLS check into two separate validator methods (hasHttpsReverbScheme / hasReverbTlsEnabled) after the first test run showed the combined check reported the wrong config key when only useTLS was toggled in the 'Reverb TLS disabled' dataset case — every other check in this validator reports exactly the key that was changed, so kept that convention.

Verification: vendor/bin/sail artisan test --compact tests/Feature/Platform/ProductionConfigurationValidatorTest.php -> 32/32 passed. Full suite: vendor/bin/sail artisan test --compact -> 763/763 passed. vendor/bin/sail bin pint --format agent on all changed files -> passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Fixed config/reverb.php's allowed_origins, which was hardcoded to a wildcard ['*'] regardless of environment — it now derives from APP_URL's host the same way config/production.php derives allowed_hosts, so it can never be a wildcard. Extended ProductionConfigurationValidator with four new checks: Reverb app key and secret must be set and must not equal the checked-in dev values ('local-reverb-key'/'local-reverb-secret'), the Reverb scheme must be 'https', and TLS (useTLS) must be enabled — plus a check that allowed_origins is never empty or wildcarded. No .env.prod.example file exists in this repo (the task's reference to one appears stale); the enforceable fix lives in the config file and the validator that already gates application boot on 20 other production config keys. Verified with 8 new validator test cases plus a full suite run: 763/763 passing.
<!-- SECTION:FINAL_SUMMARY:END -->
