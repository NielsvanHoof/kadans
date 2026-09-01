---
paths:
  - app/Dossiers/Support/StoredFileResponse.php
---

# Support

## Inertia visits to file downloads use Inertia::location
A 302 to a signed S3 URL is correct for a top-level navigation (plain <a>). Inertia XHR follows that 302 as a cross-origin request, and the bucket CORS only allows PUT for uploads, so the preflight fails. A streamed local response looks empty to Inertia middleware, which then redirects back. When the request has X-Inertia, StoredFileResponse must return Inertia::location(request()->fullUrl()) so the browser re-requests the same URL as a navigation — the same pattern as OAuthController::redirect(). Do not "fix" this by widening S3 CORS to GET.
