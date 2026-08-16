---
id: TASK-3.6
title: Pin trusted proxies to the load balancer instead of any direct peer
status: Done
assignee:
  - cursor
created_date: '2026-08-06 09:50'
updated_date: '2026-08-06 11:04'
labels: []
dependencies: []
references:
  - bootstrap/app.php
  - app/Platform/Services/Production/ProductionConfigurationValidator.php
  - config/production.php
  - app/Providers/AppServiceProvider.php
  - tests/Feature/Platform/TrustedProxiesTest.php
  - .env.example
  - docs/security.md
modified_files:
  - bootstrap/app.php
  - app/Providers/AppServiceProvider.php
  - app/Platform/Services/Production/ProductionConfigurationValidator.php
  - config/production.php
  - .env.example
  - docs/security.md
  - tests/Feature/Platform/ProductionConfigurationValidatorTest.php
  - tests/Feature/Platform/TrustedProxiesTest.php
parent_task_id: TASK-3
priority: high
type: bug
ordinal: 14000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Trusted proxies are configured with the REMOTE_ADDR sentinel, which tells Symfony to trust whoever opened the TCP connection as a proxy. If the task is reachable other than through the load balancer - and the allowed_hosts config explicitly whitelists bare IPv4 Host headers, which implies it is - then an attacker sets an X-Forwarded-For header per request and:

- defeats the login rate limiter, which is keyed on email and IP
- defeats the throttle on portal token exchange, turning a rate-limited endpoint into an unlimited one
- poisons the ip column on every audit row, destroying the forensic value of the audit log the threat model depends on

ProductionConfigurationValidator only checks for the literal string REMOTE_ADDR, so it currently blesses the weak setting.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Trusted proxies in production are restricted to the load balancer or VPC range rather than any direct peer
- [x] #2 ProductionConfigurationValidator fails boot when trusted proxies are unset or set to the REMOTE_ADDR sentinel in production
- [x] #3 Client IP resolution still works correctly behind the load balancer, verified by a test asserting the resolved IP on a forwarded request
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Wire trustProxies in bootstrap/app.php to config('production.trusted_proxies') instead of the hardcoded REMOTE_ADDR sentinel, keeping HEADER_X_FORWARDED_AWS_ELB.
2. Flip ProductionConfigurationValidator::hasTrustedProxies(): require a non-empty string and reject REMOTE_ADDR (and equally unsafe * / ** catch-alls). Allow explicit CIDRs/IPs and Symfony's PRIVATE_SUBNETS sentinel (VPC-range option).
3. Update ProductionConfigurationValidatorTest: safe baseline uses an explicit CIDR (e.g. 10.0.0.0/8); add dataset cases for REMOTE_ADDR and *.
4. Add TrustedProxiesTest: when REMOTE_ADDR is a trusted CIDR peer, X-Forwarded-For becomes request->ip(); when the peer is outside the trusted range, XFF is ignored (spoof rejected). Clear Request trusted-proxy static state in finally (same leak pattern as TrustedHostsTest).
5. Update .env.example comment + docs/security.md so production must set LB/VPC CIDRs or PRIVATE_SUBNETS; local may keep REMOTE_ADDR because the validator only runs in production.

Adjustment: trustProxies(at:) cannot take a closure and config() is unavailable during the withMiddleware callback (artisan blew up with Target class [config] does not exist). bootstrap/app.php now only sets HEADER_X_FORWARDED_AWS_ELB; AppServiceProvider::configureTrustedProxies() applies TrustProxies::at(config('production.trusted_proxies')) once config is loaded.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
First attempt to pass config('production.trusted_proxies') into trustProxies(at:) inside withMiddleware failed: config repository is not bound yet (Target class [config] does not exist). Split: headers in bootstrap, TrustProxies::at() in AppServiceProvider::configureTrustedProxies().

Validator flipped from requiring REMOTE_ADDR to rejecting it (plus * / ** / 0.0.0.0/0 / ::/0). PRIVATE_SUBNETS and concrete CIDRs allowed.

Verification: vendor/bin/sail artisan test --compact tests/Feature/Platform/TrustedProxiesTest.php tests/Feature/Platform/ProductionConfigurationValidatorTest.php -> 37/37 passed. Pint on changed PHP files -> passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Stopped trusting any direct TCP peer as a proxy. bootstrap/app.php no longer hardcodes REMOTE_ADDR; it only configures AWS ELB forwarded headers. AppServiceProvider applies TrustProxies::at(config('production.trusted_proxies')) after config loads (trustProxies(at:) cannot take a deferred closure, and calling config() inside withMiddleware fatals during artisan boot).

ProductionConfigurationValidator now requires a non-empty proxy list and rejects REMOTE_ADDR, *, **, and catch-all CIDRs. Explicit LB/VPC CIDRs and Symfony's PRIVATE_SUBNETS remain valid. Local .env.example may still use REMOTE_ADDR because the validator only runs in production; comments and docs/security.md spell out the production requirement.

Tests: ProductionConfigurationValidatorTest baseline uses 10.0.0.0/8 and asserts REMOTE_ADDR/* fail; TrustedProxiesTest proves X-Forwarded-For is honored behind a trusted VPC peer and ignored from a public direct peer. vendor/bin/sail artisan test --compact tests/Feature/Platform/TrustedProxiesTest.php tests/Feature/Platform/ProductionConfigurationValidatorTest.php → 37/37 passed.

Deploy note: set TRUSTED_PROXIES to the ALB/VPC CIDR (or PRIVATE_SUBNETS) before promoting to production, or boot will fail closed.
<!-- SECTION:FINAL_SUMMARY:END -->
