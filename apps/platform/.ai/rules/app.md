---
paths:
  - 'app/**'
  - 'app/**/*.php'
---

# App

## Domain layering is enforced — invert upward dependencies with a contract
A domain may use domains below it and its own layer siblings, never above. Enforced in `tests/Pest.php`.

```
L0 Shared → L1 Accounts, Integrations → L2 Tenancy → L3 Questionnaires
L4 Clients, Dossiers, DocumentProcessing, AudioProcessing, Pipelines
L5 ClientPortal → L6 Reporting, Platform, WorkspaceNotifications
```

Integrations is an L1 sibling of Accounts (OAuth connections / Socialite). Login needs the token vault; calendar product UI stays higher and calls Integrations. Audit is cross-cutting, not a layer: any domain may log activity, but Audit itself may only reach Accounts and Tenancy. Shared must never import DocumentProcessing or AudioProcessing.

When a lower layer needs something from a higher one, **invert it**: the lower layer owns the contract, the higher one binds it. Existing examples — `Tenancy\Contracts\SupportSessionState`, `Dossiers\Contracts\ClientPortalReminders`, `Pipelines\Contracts\PipelineRunner`, `Shared\Cache\TenantCacheInvalidator`.

Domain bindings and `Gate::policy()` calls live in that domain's `*ServiceProvider` (registered in `bootstrap/providers.php`). `AppServiceProvider` stays thin: defaults, morph map, factory discovery, local tooling.

Migrations and factories stay under `database/` — never inside `app/{Domain}`.

## Prefer typed Config helpers; use get() for nullable
Prefer Config::string/integer/boolean/array over config() when the value’s type is known.
When a key can legitimately be null (env() without default — credentials, endpoints, queue URLs, trusted_proxies, debugbar.storage.open), use Config::get() instead: Config::string() throws on null, and a default is ignored when the key exists as null.

## Helper swaps that look right but regress: blank(), Uri::of(), once()
Audited the Laravel 13 helper set against this codebase. Rector already applies LARAVEL_IF_HELPERS and EmptyToBlankAndFilledFuncRector, so throw_if/abort_if/blank are largely done. Three tempting remaining swaps are net regressions — do not "clean them up":

blank() is trim($v) === '' — ASCII only. This codebase uses mb_trim() deliberately, so blank() would start accepting non-breaking-space-only input as valid. Also, `is_string($x) && $x !== ''` narrows the type for phpstan level 8; filled() does not.

Uri::of() is league/uri and THROWS SyntaxError where parse_url() returns false (e.g. "http://:80"). Do not use it on operator-supplied config — ContentSecurityPolicy::originFromUrl() builds a header on every HTML response from S3 disk config, so a typo'd env var would turn a quietly-omitted origin into a site-wide 500.

once() is flushed by FlushOnce on OperationTerminated (config/octane.php), so it must not replace PermissionRegistry's ?array property — that would re-validate the whole registry every request on a per-worker singleton.

Arr::pluck()/Collection::values()->all() return array<int,mixed> and lose list<T>; keep array_map/array_values where a list<> return type is declared.

## Known scaling ceilings, with the trigger that reopens each
Found in an August 2026 performance audit, deliberately not fixed: they do not bite at tens of firms. Reopen when the trigger fires rather than re-deriving them.

- **Spatie permission cache is one global blob.** `PermissionRegistrar` loads every permission `->with('roles')` into one Redis key and re-hydrates per request. With `teams => true`, `SyncTenantRolesAction` creates 5 roles + ~150 pivot rows per tenant, so the blob grows with platform size, not tenant size. `forgetCachedPermissions()` is global. Trigger: >200 tenants, or a p95 regression.
- **Production RDS is `db.t4g.micro`**, and `databasePubliclyAccessible: true` means every query from Laravel Cloud crosses the public internet (infra/src/environments/production.ts). Trigger: before the first real client load test.
- **Reverb is a single node** — `REVERB_SCALING_ENABLED` defaults false and no `REVERB_SCALING_*` keys exist. Trigger: scaling past one app instance.
- **Schedulers are O(tenants).** `pipelines:monitor` and `pipelines:retry-due` do `Tenant::query()->each()` every 5 minutes. A cross-tenant `GROUP BY tenant_id` returns the same thing. Trigger: >200 tenants.
- **Scout truncates at 1000 matches.** `ScoutSearchFilter` caps keys and re-applies via `whereKey()`, so paginated totals are wrong past the cap. The pg_trgm indexes fixed the scan cost, not this. Trigger: any tenant exceeding 1000 matches.
- **Broadcast fan-out is tenant-wide per pipeline transition** — bulk-uploading 100 documents queues ~500 BroadcastEvent jobs.

## `$factory()->method(...)` is not a lazy callable
First-class callable syntax evaluates the *receiver expression* immediately and only defers the final method call. So passing `$callback()->toArray(...)` where a lazy `Closure` is expected runs `$callback()` on every invocation.

This shipped briefly in `WorkspaceDashboardCache::rememberMetrics()`, where the callback is the sixteen-aggregate dashboard recompute the cache exists to avoid — so the cache still returned the right value and quietly did all the work on every hit. Nothing failed; only a test asserting the callback did *not* run caught it.

Write `static fn (): array => $callback()->toArray()` instead. Rector's ClosureToArrowFunctionRector rewrites a full closure to that arrow form, which is still lazy and is the shape to keep; do not "simplify" it further to `(...)`.

The general rule: `(...)` is only equivalent to a closure when the part before `->` is a plain variable. Any call, chain, or index in front of it is evaluated eagerly.
