---
paths:
  - app/Dossiers/Support/StoredFileResponse.php
---

# Support

## Inertia visits to file downloads use Inertia::location
A 302 to a signed S3 URL is correct for a top-level navigation (plain <a>). Inertia XHR follows that 302 as a cross-origin request, and the bucket CORS only allows PUT for uploads, so the preflight fails. A streamed local response looks empty to Inertia middleware, which then redirects back. When the request has X-Inertia, StoredFileResponse must return Inertia::location(request()->fullUrl()) so the browser re-requests the same URL as a navigation — the same pattern as OAuthController::redirect(). Do not "fix" this by widening S3 CORS to GET.

## Stored-file download callers return a bare Response, never the union
`StoredFileResponse::serve()` has three outcomes: a signed-URL redirect (S3), a streamed body (local/CI disk), and — when the request carries `X-Inertia` — `Inertia::location()`, which is a 409 `Illuminate\Http\Response`.

Every caller (`DownloadDossierExportAction::handle`, and the `download`/`preview` methods on `UploadedDocumentController`, `AudioRecordingController`, `DossierExportController`, `PipelineController`, `ClientPortalUploadController`) must declare a bare `Symfony\Component\HttpFoundation\Response`. Do NOT write `RedirectResponse|StreamedResponse|Response` there: the union collapses to `Response` anyway (both other arms extend it), and Rector's `NarrowWideUnionReturnTypeRector` then strips `Response` back off, because it cannot resolve `Inertia::location()` through the facade. The result is a TypeError on every Inertia visit to a download URL — CI caught exactly one of seven such signatures in Sept 2026 (`PresignedDownloadTest`), the other six were silently broken.

`serve()` itself keeps the explicit union: Rector resolves all three branches at the source and leaves it alone, and naming them there documents the behaviour once.

Guarded by "every stored-file delivery method admits an Inertia location response" in `tests/Architecture/AppArchitectureTest.php`. Prefer that guard over a `rector.php` skip — a skip was tried and is not needed once the type is a bare `Response`.
