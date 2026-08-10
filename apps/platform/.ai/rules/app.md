---
paths:
  - 'app/**'
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

When a lower layer needs something from a higher one, **invert it**: the lower layer owns the contract, the higher one binds it. Existing examples — `Tenancy\Contracts\SupportSessionState`, `Dossiers\Contracts\ClientPortalReminders`, `Pipelines\Contracts\PipelineRetryRunner`, `Shared\Cache\TenantCacheInvalidator`.

Domain bindings and `Gate::policy()` calls live in that domain's `*ServiceProvider` (registered in `bootstrap/providers.php`). `AppServiceProvider` stays thin: defaults, morph map, factory discovery, local tooling.

Migrations and factories stay under `database/` — never inside `app/{Domain}`.
