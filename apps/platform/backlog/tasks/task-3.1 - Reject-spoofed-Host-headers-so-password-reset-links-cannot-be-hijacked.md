---
id: TASK-3.1
title: Reject spoofed Host headers so password reset links cannot be hijacked
status: Done
assignee:
  - '@claude'
created_date: '2026-08-06 09:50'
updated_date: '2026-08-06 10:24'
labels: []
dependencies: []
references:
  - config/production.php
  - bootstrap/app.php
parent_task_id: TASK-3
priority: high
type: bug
ordinal: 9000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The `allowed_hosts` config includes the pattern for any bare IPv4 address, so Laravel `TrustHosts` accepts any IPv4 `Host` header. Nothing calls `URL::forceRootUrl`, so absolute URLs are derived from the request root.

An attacker POSTs to /forgot-password with a Host header pointing at a host they control. Fortify builds the reset link from that root, so the victim receives a legitimate-looking email from the real firm containing a link to the attacker, who captures the reset token. That is full staff account takeover, and it chains with the missing reset rate limiter to allow spraying a whole firm.

The IPv4 pattern was presumably added for ALB or ECS health checks, but /up and /ready do not need a global host allowance.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A request carrying an unrecognised Host header is rejected rather than served
- [x] #2 Absolute URLs generated in production always use the configured application host regardless of the request Host header
- [x] #3 Health and readiness endpoints still respond to the load balancer without requiring a global host allowance
- [x] #4 A feature test asserts a spoofed Host header never appears in a generated password reset URL
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Remove the blanket IPv4 allowed_hosts pattern from config/production.php, leaving only the exact app-host regex.
2. Force absolute URL generation in production (AppServiceProvider) via URL::useOrigin(config('app.url')) + URL::forceScheme('https'), so reset links never depend on the request Host header even as defense in depth.
3. Extend ProductionConfigurationValidator::hasAllowedHosts() to reject any allowed_hosts pattern that matches canary hosts (metadata IP, arbitrary IP, arbitrary domain), making the IPv4-wildcard regression class impossible to reintroduce.
4. Add tests: TrustedHostsTest (spoofed Host header rejected with 400, legitimate host accepted), ReadinessEndpointTest (health/ready still 200 without any Host allowance), PasswordResetTest (generated reset URL never contains a spoofed host), ProductionConfigurationValidatorTest (overly-permissive allowed_hosts patterns rejected).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Investigated request lifecycle: /up and /ready never call $request->getHost(), so they were never protected by the IPv4 wildcard and don't need any host allowance (Laravel's built-in health route and ReadinessController both return plain responses with no URL generation). Confirmed via Symfony Request::getHost() source that TrustHosts only throws SuspiciousOperationException lazily when getHost() is actually invoked (e.g. by url()/route() during Fortify's reset link generation).

Discovered TestCase's get()/post() helpers cannot simulate a genuinely spoofed Host header — MakesHttpRequests::prepareUrlForRequest() always resolves the URI via url($uri), and Symfony's Request::create() gives URI-derived host priority over any HTTP_HOST server var, silently overriding withHeaders(['Host' => ...]). Verified this with vendor/bin/sail bin pest --agent probes. Worked around it for TrustedHostsTest by building Request::create() with a fully-qualified spoofed URL and dispatching it directly through the HTTP kernel — bypassing the TestCase URL-prefixing entirely.

Found and fixed a second bug during verification: URL::useOrigin()/forceRootUrl() alone does not fix the scheme — UrlGenerator::formatScheme() falls back to $request->getScheme() unless URL::forceScheme() is also called. Without it, a reset link would still read 'http://' instead of the required 'https://'. Added forceScheme('https') alongside.

Request::setTrustedHosts() sets a Symfony base-class static property that outlives the per-test Laravel container — TrustedHostsTest resets it to [] in a finally block to avoid leaking 'Untrusted Host' failures into every other feature test in the same process (caught this via a full suite run before fixing).

Verification: vendor/bin/sail artisan test --compact tests/Feature/Platform/TrustedHostsTest.php tests/Feature/Platform/ReadinessEndpointTest.php tests/Feature/Platform/ProductionConfigurationValidatorTest.php tests/Feature/Auth/PasswordResetTest.php -> 41/41 passed. Full suite: vendor/bin/sail artisan test --compact -> 753/753 passed. vendor/bin/sail bin pint --format agent on all changed files -> passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Removed the blanket IPv4 host pattern from config/production.php so allowed_hosts only matches the exact configured app host. Added URL::useOrigin()+URL::forceScheme('https') in AppServiceProvider (production only) so absolute URLs, including password reset links, are always derived from config('app.url') regardless of the request Host header. Extended ProductionConfigurationValidator::hasAllowedHosts() with a canary-host check so an overly permissive allowed_hosts pattern (any IPv4, any host) fails application boot in production, preventing this regression class from returning. /up and /ready were confirmed to never call getHost(), so they keep working for the load balancer without any special-case host allowance. Verified with new/updated tests (TrustedHostsTest, ReadinessEndpointTest, PasswordResetTest, ProductionConfigurationValidatorTest) plus a full suite run: 753/753 passing.
<!-- SECTION:FINAL_SUMMARY:END -->
