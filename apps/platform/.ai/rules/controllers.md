---
paths:
  - 'app/*/Http/Controllers/**'
---

# Controllers

## Controllers stay thin: authorize, call Action/Query, render
Three steps only: `$this->authorize(...)`, call an Action or Query `handle()`, return `Inertia::render()` / redirect / download / `response()->json()`.

Multi-method controllers with explicit verb routes. No `Route::resource`/`apiResource`. Middleware goes on route groups in `routes/*.php`, never `HasMiddleware` or `#[Middleware]` on the controller.

There is no top-level `app/Http` — HTTP lives inside each domain.

Intentional cross-tenant lookups use `TenantOwnedModel::queryWithoutTenantScope()`, never `withoutGlobalScopes()` (greppable by design).

## Serve stored files via presigned URL, except the data-subject archive
Octane runs in worker mode, so a streamed download holds one of a small worker pool for the entire transfer. Route stored-file delivery through `Dossiers\Support\StoredFileResponse`, which redirects to a short-lived signed URL on S3 and only streams on local/CI. It reuses `ResolveDocumentTemporaryUrlAction::forStoredFile()`, so the hardened Content-Disposition builder still sees every client-supplied filename — do not build that header at a call site.

Gate first, redirect second: the malware verdict check and the audit-log write stay in the controller, before the response. Only the byte transport moved.

One deliberate exception: `DataSubjectRequestController::download()` keeps streaming. Its docblock records why — that archive is everything the firm holds about one person, so it must not become a bearer link that outlives the session it was issued in. Do not "finish the job" by converting it.
