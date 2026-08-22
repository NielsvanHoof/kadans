---
id: TASK-43.2
title: Adapt application configuration for Laravel Cloud
status: Done
assignee:
  - '@nielsvanhoof'
created_date: '2026-08-22 16:05'
updated_date: '2026-08-22 17:01'
labels: []
dependencies: []
parent_task_id: TASK-43
priority: high
type: chore
ordinal: 98000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Configuration changes needed for the app to boot and behave correctly on Laravel Cloud.

- TRUSTED_PROXIES becomes PRIVATE_SUBNETS, which ProductionConfigurationValidator::hasTrustedProxies() already permits and Symfony expands natively. No validator change required. Rename the forwarded-header constant from HEADER_X_FORWARDED_AWS_ELB to the explicit FOR|PROTO|PORT set now that no ELB is involved.
- REDIS_SCHEME=tls must be set explicitly: Cloud Valkey injects REDIS_HOST/REDIS_PASSWORD but the validator requires scheme=tls on both the default and cache connections.
- With RDS publicly addressable, DB_SSLMODE=prefer is no longer acceptable. Move to verify-full with the RDS CA bundle, and guard it in the validator.
- config/health.php pins CacheHealthResultStore to the file store, so /health reads a different store than the scheduler wrote. Already wrong today (the scheduler is a separate container); switch to redis.
- config/inertia.php enables SSR with no ssr.tsx entry point and no SSR server, causing an attempted connection to 127.0.0.1:13714 on every render.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 TRUSTED_PROXIES=PRIVATE_SUBNETS is used and ProductionConfigurationValidator still rejects REMOTE_ADDR, * and 0.0.0.0/0
- [x] #2 REDIS_SCHEME=tls is documented in .env.example and satisfied on both redis connections
- [x] #3 ProductionConfigurationValidator fails boot when pgsql sslmode is weaker than verify-ca in shipped environments
- [x] #4 Health check results written by the scheduler are readable by the web cluster
- [x] #5 Inertia SSR is either disabled or fully wired, with no per-render connection attempt to an absent SSR server
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Two plan items turned out differently than expected:

1. `config/health.php` does NOT pin CacheHealthResultStore to the file store — that whole block is commented out and the only active store is EloquentHealthResultStore, which writes to the database and is therefore already shared across replicas. Nothing to fix. Added a guard instead: HealthChecksTest now fails if a store is added that only one instance can read. It checks the cache driver rather than the class, because a redis-backed CacheHealthResultStore is perfectly fine while a file-backed one is not — banning the class outright would have been wrong. Verified the guard by temporarily uncommenting the file-backed store and watching it fail.

2. TRUSTED_PROXIES needed no validator change at all. `hasTrustedProxies()` already permits Symfony PRIVATE_SUBNETS (its own docblock names it), and Symfony expands that token natively. So the shipped value becomes PRIVATE_SUBNETS — Laravel Cloud fronts the app with Cloudflare and its own ingress on a private network, and the pod address is not stable enough to enumerate but is always RFC1918. Documented in .env.example; the code change was only spelling HEADER_X_FORWARDED_AWS_ELB out as its three constituent constants now that no ELB is involved (identical bitmask, clearer intent).

Real changes:
- config/database.php gains `sslrootcert` (Laravel PostgresConnector already threads it into the DSN) and a comment explaining why the `prefer` default is fine for Sail and not for anything reachable off its own network.
- ProductionConfigurationValidator gains two checks: pgsql sslmode must be verify-ca/verify-full, and sslrootcert must name a readable file. The second matters because verify-full without a root certificate fails at connection time rather than at boot — a deploy that looks healthy and cannot reach its database. Six new dataset rows cover prefer, require, disable, null, empty and unreadable.
- config/inertia.php SSR set to false. It was enabled with no ssr.tsx entry point, no build:ssr in any build, and nothing on port 13714, so every render attempted a connection and fell back. Comment spells out the three things that must change together to turn it back on.
- REDIS_SCHEME already drives both the default and cache connections, so nothing to change in code; .env.example now says why it must be tls on Cloud and that Valkey injects host and password but not the scheme.

Validation: 1406 PHP tests, 511 frontend tests, phpstan/pint/rector/tsc all clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Adapted the application configuration for a platform where the database is reachable over the public internet and the app sits behind someone else edge.

Added enforced database TLS (sslmode verify-full plus a readable sslrootcert, both checked by ProductionConfigurationValidator at boot), documented PRIVATE_SUBNETS as the shipped TRUSTED_PROXIES value and spelled out the forwarded-header set now that no ELB is involved, turned off the Inertia SSR flag that was costing a failed localhost connection on every render, and documented the Redis TLS requirement in .env.example.

Two planned fixes were not needed: the health result store is already database-backed, not file-backed, and the proxy validator already accepted PRIVATE_SUBNETS. Added a regression guard for the first (verified by temporarily reintroducing the file-backed store and watching it fail).

Verified with 1406 PHP tests, 511 frontend tests, and a clean phpstan/pint/rector/tsc run.
<!-- SECTION:FINAL_SUMMARY:END -->
