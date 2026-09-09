---
paths:
  - 'app/**/Http/Controllers/**'
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

## Pick currentUser() vs $request->user() by whether a User is required
Both accessors are correct; the choice is not style. Use `ResolvesCurrentUser::currentUser()` when the action cannot proceed without a User — it aborts 403 if there is none, which is why it must never wrap an optional read. Use `$request->user()` where absence is a valid outcome: nullable-safe capability props (`$request->user()?->can(...) ?? false`), explicit `instanceof User` checks on guest-reachable routes (see OAuthController, where the callback runs for signed-out users completing social login), and arguments to explicitly nullable parameters (EndSupportSessionAction::handle(?User $user = null)).

Five controllers use both side by side for exactly these reasons — ClientController, DossierController, PipelineController, SupportSessionController, OAuthController. That is not drift; do not "normalise" it. Swapping a `?->can() ?? false` prop to `currentUser()->can()` turns a graceful false into a 403 for any guest-reachable page.

## HTTP status spellings: constant for explicit statuses, bare int in abort()
Three spellings coexist on purpose; do not unify them.

`Response::HTTP_*` (unaliased `Symfony\Component\HttpFoundation\Response`) is the convention wherever a status constant is written — 27 files.

`Response as HttpResponse` is required, not stylistic, in files that also import `Inertia\Response`, which takes the bare name: PipelineController, UploadedDocumentController, AudioRecordingController. In DossierExportController, ClientPortalUploadController and DownloadDossierExportAction the alias is kept for consistency with those siblings, and it names a return type there rather than a status constant.

Bare integers in `abort(404)` / `abort_unless(..., 404)` are idiomatic Laravel and orthogonal to the above — a file can import a Response type for its signature and still abort with a literal. Around 21 such sites exist; leave them.
