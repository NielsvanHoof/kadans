---
paths:
  - 'app/**'
  - 'app/**/*.php'
---

# App

## Domain layering is enforced — invert upward dependencies with a contract
A domain may use domains below it and its own layer siblings, never above. Enforced in `tests/Pest.php`.

```
L0 Shared → L1 Accounts → L2 Tenancy → L3 Questionnaires
L4 Clients, Dossiers, DocumentProcessing, AudioProcessing, Pipelines
L5 ClientPortal → L6 Reporting, Platform, WorkspaceNotifications
```

Audit is cross-cutting, not a layer: any domain may log activity, but Audit itself may only reach Accounts and Tenancy. Shared must never import DocumentProcessing or AudioProcessing.

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
